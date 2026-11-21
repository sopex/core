<?php

/*
 * Copyright (C) 2015-2023 Franco Fichtner <franco@opnsense.org>
 * Copyright (C) 2014 Deciso B.V.
 * Copyright (C) 2004-2009 Scott Ullrich <sullrich@gmail.com>
 * Copyright (C) 2008 Shrew Soft Inc. <mgrooms@shrew.net>
 * Copyright (C) 2003-2004 Manuel Kasper <mk@neon1.net>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

require_once("guiconfig.inc");
require_once("interfaces.inc");
require_once("console.inc");
require_once("filter.inc");
require_once("rrd.inc");
require_once("system.inc");

use OPNsense\Backup\Local;

/**
 * restore config section
 * @param array $section_sets config section sets
 * @param string $new_contents xml content
 * @return bool status
 */
function restore_config_section($section_sets, $new_contents)
{
    global $config;

    $tmpxml = '/var/lib/php/tmp/tmpxml';
    $xml = null;

    try {
        file_put_contents($tmpxml, $new_contents);
        $xml = load_config_from_file($tmpxml);
    } catch (Exception $e) { }

    @unlink($tmpxml);

    if (!is_array($xml)) {
        return false;
    }

    $restored = [];
    $failed = [];

    foreach ($section_sets as $section_set) {
        $sections = explode(',', $section_set);
        $found = [];

        /* first find the existing sections from the set to be imported */
        foreach ($sections as $section) {
            $new = &$xml;

            $path = explode('.', $section);
            $target = array_pop($path);

            foreach ($path as $node) {
                if (!isset($new[$node])) {
                    continue 2;
                }
                $new = &$new[$node];
            }

            if (isset($new[$target])) {
                $found[] = $section;
            }
        }

        /* keep current config and skip to next one considering this set (and subsequently the import) failed */
        if (!count($found)) {
            $failed[] = $section_set;
            continue;
        }

        /* secondly delete every old config section to be able to force a migration too */
        foreach (array_diff($sections, $found) as $section) {
            $old = &$config;

            $path = explode('.', $section);
            $target = array_pop($path);

            foreach ($path as $node) {
                if (!isset($old[$node])) {
                    continue 2;
                }
                $old = &$old[$node];
            }

            if (isset($old[$target])) {
                unset($old[$target]);
                $restored[] = $section;
            }
        }

        /* thirdly and lastly import the found sections */
        foreach ($found as $section) {
            $old = &$config;
            $new = &$xml;

            $path = explode('.', $section);
            $target = array_pop($path);

            foreach ($path as $node) {
                if (!isset($new[$node])) {
                    continue 2;
                }
                $new = &$new[$node];
                if (!isset($old[$node])) {
                    $old[$node] = [];
                }
                $old = &$old[$node];
            }

            if (isset($new[$target])) {
                $old[$target] = $new[$target];
                $restored[] = $section;
            }
        }
    }

    if (count($restored) && !count($failed)) {
        /* restored but may not have been modified at all */
        write_config(sprintf('Restored sections (%s) of config file', join(',', $restored)));
        convert_config();
    }

    return $failed;
}

/* config areas that are not suitable for config sync live here */
$areas = [
    'bridges' => gettext('Bridge Devices'),
    'gifs' => gettext('GIF Devices'),
    'interfaces' => gettext('Interfaces'),
    'laggs' => gettext('LAGG Devices'),
    'ppps' => gettext('Point-to-Point Devices'),
    'rrddata' => gettext('RRD Data'),
    'vlans' => gettext('VLAN Devices'),
    'wireless' => gettext('Wireless Devices'),
];

foreach (plugins_xmlrpc_sync() as $area) {
    if (!empty($area['section'])) {
        $areas[$area['section']] = $area['description'];
    }
}

natcasesort($areas);

$backupFactory = new OPNsense\Backup\BackupFactory();
$do_reboot = false;
$preflight_report = null;
$preflight_token = null;
$preflight_remap = [];
$preflight_missing = [];

/**
 * helper to serialize NIC remap array into safe old=new,... string
 * @param array $raw_remap
 * @return array [$clean_map, $serialized]
 */
function sanitize_nic_remap($raw_remap)
{
    $clean = [];
    $pairs = [];
    if (is_array($raw_remap)) {
        foreach ($raw_remap as $old => $new) {
            $old = trim((string)$old);
            $new = trim((string)$new);
            if (
                $new !== '' &&
                preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,31}$/', $old) &&
                preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,31}$/', $new)
            ) {
                $clean[$old] = $new;
                $pairs[] = "{$old}={$new}";
            }
        }
    }
    return [$clean, implode(',', $pairs)];
}

/**
 * remove staged pre-flight restore file and clear session state
 */
function clear_staged_restore()
{
    $opened = false;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
        $opened = true;
    }
    if (!empty($_SESSION['restore_preflight']['path'])) {
        @unlink($_SESSION['restore_preflight']['path']);
    }
    unset($_SESSION['restore_preflight']);
    if ($opened) {
        session_write_close();
    }
}

/**
 * execute actual restore of staged XML data
 * @param string $source_path path to XML file to restore
 * @param array $opts restore options (restorearea, rebootafterrestore, keepconsole, flush_history)
 * @param array $areas available area map
 * @param array &$input_errors error output
 * @param string|null &$savemsg info output
 * @param bool &$do_reboot reboot flag output
 */
function apply_staged_restore($source_path, $opts, $areas, &$input_errors, &$savemsg, &$do_reboot)
{
    global $config;

    $data = @file_get_contents($source_path);
    if ($data === false || $data === '') {
        $input_errors[] = gettext('Could not read staged configuration file for restore.');
        return;
    }

    if (!empty($opts['restorearea'])) {
        $ret = restore_config_section($opts['restorearea'], $data);
        if ($ret === false) {
            $input_errors[] = gettext('The selected config file could not be parsed.');
        } elseif (count($ret)) {
            $descr = [];
            foreach ($ret as $area) {
                $descr[] = $areas[$area] ?? $area;
            }
            $input_errors[] = sprintf(gettext('At least one requested restore area could not be found: %s.'), join(', ', $descr));
        } else {
            if (!empty($config['rrddata'])) {
                /* XXX we should point to the data... */
                rrd_import();
                unset($config['rrddata']);
                write_config();
                convert_config();
            }
            if (!empty($opts['rebootafterrestore'])) {
                $do_reboot = true;
            }
            $savemsg = gettext("The configuration area has been restored.");
        }
    } else {
        /* restore the entire configuration */
        $cfieldnames = [
            'usevirtualterminal',
            'primaryconsole',
            'secondaryconsole',
            'serialspeed',
            'serialusb',
            'disableconsolemenu'
        ];
        $csettings = [];
        foreach ($cfieldnames as $fieldname) {
            $csettings[$fieldname] = $config['system'][$fieldname] ?? null;
        }
        $cnf = OPNsense\Core\Config::getInstance();
        if ($cnf->restoreBackup($source_path)) {
            if (!empty($opts['rebootafterrestore'])) {
                $do_reboot = true;
            }
            $config = parse_config();
            $flush = false;
            if (!empty($opts['keepconsole'])) {
                // restore existing console settings
                foreach ($csettings as $fieldname => $fieldcontent) {
                    if ($fieldcontent === null && isset($config[$fieldname])) {
                        unset($config[$fieldname]);
                    } else {
                        $config['system'][$fieldname] = $fieldcontent;
                    }
                }
                $flush = true;
            }
            if (!empty($config['rrddata'])) {
                /* XXX we should point to the data... */
                rrd_import();
                unset($config['rrddata']);
                $flush = true;
            }
            if ($flush) {
                write_config();
                convert_config();
            }
            $savemsg = gettext("The configuration has been restored.");
        } else {
            $input_errors[] = gettext("The configuration could not be restored.");
        }
    }

    if (empty($input_errors)) {
        if (is_interface_mismatch(false)) {
            $savemsg .= ' ' . sprintf(
                gettext(
                    "Interfaces do not seem to match, please check the %sassignments%s now for missing devices."
                ),
                '<a href="/ui/interfaces/assignment">',
                '</a>'
            );
            if ($do_reboot) {
                $savemsg .= ' ' . gettext('Postponing reboot.');
                $do_reboot = false;
            }
        }

        if ($do_reboot) {
            $savemsg .= ' ' . gettext("The system is rebooting now. This may take one minute.");
        }
        if (!empty($opts['flush_history'])) {
            configd_run('system flush config_history');
            write_config('System restore flushed local history');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pconfig = [];
    $pconfig['backupcount'] = isset($config['system']['backupcount']) ? $config['system']['backupcount'] : null;
    $pconfig['rebootafterrestore'] = true;
    $pconfig['keepconsole'] = true;
    $pconfig['flush_history'] = true;
    $pconfig['decrypt'] = false;
    foreach ($backupFactory->listProviders() as $providerId => $provider) {
        foreach ($provider['handle']->getConfigurationFields() as $field) {
            $fieldId = $providerId . "_" .$field['name'];
            $pconfig[$fieldId] = $field['value'];
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_errors = [];
    $pconfig = $_POST;
    $mode = null;

    foreach (array_keys($backupFactory->listProviders()) as $providerName) {
        if (!empty($pconfig["setup_{$providerName}"])) {
            $mode = "setup_{$providerName}";
        }
    }

    if (empty($mode)) {
        if (!empty($pconfig['restore_cancel'])) {
            $mode = "restore_cancel";
        } elseif (!empty($pconfig['restore_apply'])) {
            $mode = "restore_apply";
        } elseif (!empty($pconfig['restore'])) {
            $mode = "restore";
        } elseif (!empty($pconfig['download'])) {
            $mode = "download";
        }
    }

    if ($mode == "download") {
        if (!empty($_POST['encrypt']) && (empty($_POST['encrypt_password']) || empty($_POST['encrypt_passconf']))) {
            $input_errors[] = gettext("You must supply and confirm the password for encryption.");
        } elseif (!empty($_POST['encrypt']) && $_POST['encrypt_password'] != $_POST['encrypt_passconf']) {
            $input_errors[] = gettext('The passwords do not match.');
        }
        if (count($input_errors) == 0) {
            $host = "{$config['system']['hostname']}.{$config['system']['domain']}";
            $name = "config-{$host}-".date("YmdHis").".xml";
            $data = "";

            /* backup entire configuration */
            $data = file_get_contents('/conf/config.xml');

            /* backup RRD data */
            if (empty($_POST['donotbackuprrd'])) {
                $rrd_data_xml = rrd_export();
                $closing_tag = "</opnsense>";
                $data = str_replace($closing_tag, $rrd_data_xml . $closing_tag, $data);
            }

            if (!empty($_POST['encrypt'])) {
                $crypter = new Local();
                /* XXX this *could* fail, not handled */
                $data = $crypter->encrypt($data, $_POST['encrypt_password']);
            }

            $size = strlen($data);
            header("Content-Type: application/octet-stream");
            header("Content-Disposition: attachment; filename={$name}");
            header("Content-Length: $size");
            if (isset($_SERVER['HTTPS'])) {
                header('Pragma: ');
                header('Cache-Control: ');
            } else {
                header("Pragma: private");
                header("Cache-Control: private, must-revalidate");
            }
            echo $data;
            exit;
        }
    } elseif ($mode == "restore_cancel") {
        clear_staged_restore();
        $savemsg = gettext('Configuration restore was cancelled.');
    } elseif ($mode == "restore_apply") {
        $user = getUserEntry($_SESSION['Username']);
        if (userHasPrivilege($user, 'user-config-readonly')) {
            $input_errors[] = gettext('You do not have the permission to perform this action.');
        }
        $staged = $_SESSION['restore_preflight'] ?? null;
        $submitted_token = (string)($_POST['preflight_token'] ?? '');
        if (
            count($input_errors) == 0 && (
                !is_array($staged) ||
                empty($staged['token']) ||
                !hash_equals($staged['token'], $submitted_token) ||
                empty($staged['path']) ||
                !file_exists($staged['path'])
            )
        ) {
            $input_errors[] = gettext('Staged configuration backup expired or is invalid. Please upload the file again.');
            clear_staged_restore();
        }

        if (count($input_errors) == 0) {
            list($preflight_remap, $remap_str) = sanitize_nic_remap($_POST['remap'] ?? []);
            $areas_str = !empty($staged['restorearea']) ? implode(';', (array)$staged['restorearea']) : '';
            $remapped_path = ($remap_str !== '') ? ('/var/lib/php/tmp/restore_remapped_' . $staged['token'] . '.xml') : '';
            $raw_report = trim((string)configdp_run(
                'firmware preflight_restore',
                [$staged['path'], $areas_str, $remap_str, $remapped_path]
            ));
            $report = json_decode($raw_report, true);
            $override = !empty($_POST['override_blockers']);

            if (!is_array($report)) {
                if ($remapped_path !== '') {
                    @unlink($remapped_path);
                }
                $input_errors[] = gettext('Pre-flight validation failed to execute.');
            } elseif (empty($report['can_proceed']) && !$override) {
                if ($remapped_path !== '') {
                    @unlink($remapped_path);
                }
                $preflight_report = $report;
                $preflight_token = $staged['token'];
                $preflight_missing = !empty($staged['original_missing'])
                    ? $staged['original_missing']
                    : ($report['interface_remapping']['missing'] ?? []);
                $input_errors[] = gettext('Restore blocked by pre-flight validation. Remap missing interfaces or check override to proceed.');
            } else {
                if ($remapped_path !== '') {
                    if (!file_exists($remapped_path) || !is_readable($remapped_path)) {
                        @unlink($remapped_path);
                        $preflight_report = $report;
                        $preflight_token = $staged['token'];
                        $preflight_missing = !empty($staged['original_missing'])
                            ? $staged['original_missing']
                            : ($report['interface_remapping']['missing'] ?? []);
                        $input_errors[] = gettext('Remapped configuration file could not be read; restore aborted.');
                    } else {
                        apply_staged_restore($remapped_path, $staged, $areas, $input_errors, $savemsg, $do_reboot);
                        @unlink($remapped_path);
                        clear_staged_restore();
                    }
                } else {
                    apply_staged_restore($staged['path'], $staged, $areas, $input_errors, $savemsg, $do_reboot);
                    clear_staged_restore();
                }
            }
        }
    } elseif ($mode == "restore") {
        // unpack data and run pre-flight validation before applying
        $data = null;
        if (!empty($_POST['decrypt']) && empty($_POST['decrypt_password'])) {
            $input_errors[] = gettext('You must supply the password for decryption.');
        }
        $user = getUserEntry($_SESSION['Username']);
        if (userHasPrivilege($user, 'user-config-readonly')) {
            $input_errors[] = gettext('You do not have the permission to perform this action.');
        }
        /* read the file contents */
        if (is_uploaded_file($_FILES['conffile']['tmp_name'])) {
            $data = file_get_contents($_FILES['conffile']['tmp_name']);
            if (empty($data)) {
                $input_errors[] = sprintf(gettext("Warning, could not read file %s"), $_FILES['conffile']['tmp_name']);
            }
        } else {
            $input_errors[] = gettext("The configuration could not be restored (file upload error).");
        }

        if (!empty($_POST['decrypt']) && $data !== null) {
            $crypter = new Local();
            $data = $crypter->decrypt($data, $_POST['decrypt_password']);
            if (empty($data)) {
                $input_errors[] = gettext('The uploaded file could not be decrypted.');
            }
        }

        if (count($input_errors) == 0) {
            clear_staged_restore();
            $token = bin2hex(random_bytes(16));
            $staged_path = "/var/lib/php/tmp/restore_preflight_{$token}.xml";
            if (@file_put_contents($staged_path, $data) === false) {
                $input_errors[] = gettext('Unable to stage uploaded configuration for pre-flight validation.');
            } else {
                @chmod($staged_path, 0600);
                $areas_str = !empty($pconfig['restorearea']) ? implode(';', (array)$pconfig['restorearea']) : '';
                $raw_report = trim((string)configdp_run(
                    'firmware preflight_restore',
                    [$staged_path, $areas_str, '', '']
                ));
                $report = json_decode($raw_report, true);
                if (!is_array($report)) {
                    @unlink($staged_path);
                    $input_errors[] = gettext('Pre-flight validation failed to analyze the uploaded configuration.');
                } else {
                    $original_missing = $report['interface_remapping']['missing'] ?? [];
                    $opened = false;
                    if (session_status() !== PHP_SESSION_ACTIVE) {
                        session_start();
                        $opened = true;
                    }
                    $_SESSION['restore_preflight'] = [
                        'token' => $token,
                        'path' => $staged_path,
                        'restorearea' => !empty($pconfig['restorearea']) ? (array)$pconfig['restorearea'] : [],
                        'rebootafterrestore' => !empty($pconfig['rebootafterrestore']),
                        'keepconsole' => !empty($pconfig['keepconsole']),
                        'flush_history' => !empty($pconfig['flush_history']),
                        'original_missing' => $original_missing,
                    ];
                    if ($opened) {
                        session_write_close();
                    }
                    $preflight_report = $report;
                    $preflight_token = $token;
                    $preflight_missing = $original_missing;
                }
            }
        }
    } elseif (!empty($mode)){
        // setup backup provider, collect provider settings and save/validate
        $providerId = substr($mode, 6);
        $provider = $backupFactory->getProvider($providerId);
        $providerSet = array();
        foreach ($provider['handle']->getConfigurationFields() as $field) {
            $fieldId = $providerId . "_" .$field['name'];
            if ($field['type'] == 'file') {
                // extract file to sent to setConfiguration()
                if (is_uploaded_file($_FILES[$fieldId]['tmp_name'])) {
                    $providerSet[$field['name']] = file_get_contents($_FILES[$fieldId]['tmp_name']);
                } else {
                    $providerSet[$field['name']] = null;
                }
            } else {
                $providerSet[$field['name']] = $pconfig[$fieldId];
            }
        }
        $input_errors = $provider['handle']->setConfiguration($providerSet);
        if (count($input_errors) == 0) {
            if ($provider['handle']->isEnabled()) {
                try {
                    $filesInBackup = $provider['handle']->backup();
                } catch (Exception $e) {
                    $filesInBackup = array();
                    $input_errors[] = $e->getMessage();
                }

                if (count($filesInBackup) == 0) {
                    $input_messages = gettext('Saved settings, but remote backup returned no files.');
                } else {
                    $input_messages = gettext("Backup successful, current file list:") . "<br>";
                    foreach ($filesInBackup as $filename) {
                         $input_messages .= "<br>" . $filename;
                    }
                }
            }
            configd_run('cron restart');
        }
    } elseif (!empty($pconfig['save'])) {
        if ($pconfig['backupcount'] != null && (!is_numeric($pconfig['backupcount']) || $pconfig['backupcount'] <= 0)) {
            $input_errors[] = gettext('Backup count must be greater than zero.');
        }
        if (count($input_errors) == 0) {
            if ($pconfig['backupcount'] != null) {
                $config['system']['backupcount'] = $pconfig['backupcount'];
            } elseif (isset($config['system']['backupcount'])) {
                unset($config['system']['backupcount']);
            }
            write_config('Changed backup revision count');
            $savemsg = get_std_save_message();
        }
    }
}

include("head.inc");
legacy_html_escape_form_data($pconfig);
?>

<body>
<?php include("fbegin.inc"); ?>

<script>

function show_value(key) {
    $('#show-' + key + '-btn').html('');
    $('#show-' + key + '-val').show();
    $("[name='" + key + "']").focus();
}

//<![CDATA[
$( document ).ready(function() {
    // show encryption password
    $("#encryptconf").change(function(event){
        event.preventDefault();
        if ($("#encryptconf").prop('checked')) {
            $("#encrypt_opts").removeClass("hidden");
        } else {
            $("#encrypt_opts").addClass("hidden");
        }
    });

    // show decryption password
    $("#decryptconf").change(function(event){
        event.preventDefault();
        if ($("#decryptconf").prop('checked')) {
            $("#decrypt_opts").removeClass("hidden");
        } else {
            $("#decrypt_opts").addClass("hidden");
        }
    });
    $("#decryptconf").change();

     $('#restorearea').change(function () {
        $("#flush_history").attr('checked', false);
         if ($('#restorearea option:selected').text() == '') {
             $.restorearea_warned = 0;
             $("#flush_history").attr('checked', true);
         } else if ($.restorearea_warned != 1) {
             $.restorearea_warned = 1;
             BootstrapDialog.confirm({
                 title: '<?= html_safe(gettext('Warning!')) ?>',
                 message: '<?= html_safe(gettext('Selecting specific restore areas during a configuration import may ' .
                     'cause loss of configuration integrity due to external references not being restored. It is ' .
                     'recommended to keep this set to the default unless you know what you are doing.')) ?>',
                 type: BootstrapDialog.TYPE_WARNING,
                 btnOKClass: 'btn-warning',
                 btnOKLabel: '<?= html_safe(gettext('I know what I am doing')) ?>',
                 btnCancelLabel: '<?= html_safe(gettext('Use the default')) ?>',
                 callback: function(result) {
                     if (!result) {
                         $('#restorearea option:selected').prop('selected', false);
                         $('#restorearea').selectpicker('refresh');
                         $.restorearea_warned = 0;
                     }
                 }
             });
         }
     });
     $.restorearea_warned = $('#restorearea option:selected').length ? 1 : 0;
});
//]]>
</script>

<section class="page-content-main">
  <div class="container-fluid">
    <div class="row">
      <?php if (isset($savemsg)) print_info_box($savemsg); ?>
      <?php if (isset($input_messages)) print_info_box($input_messages); ?>
      <?php if (isset($input_errors) && count($input_errors) > 0) print_input_errors($input_errors); ?>
      <form method="post" enctype="multipart/form-data">
<?php if (is_array($preflight_report) && !empty($preflight_token)): ?>
        <section class="col-xs-12">
          <div class="content-box tab-content table-responsive __mb">
            <input type="hidden" name="preflight_token" value="<?= html_safe($preflight_token) ?>" />
            <table class="table table-striped">
              <tbody>
                <tr>
                  <td colspan="2">
                    <strong><?= gettext('Pre-Flight Validation Report (Restore Preview)') ?></strong>
                    &nbsp;
                    <span class="label label-danger"><?= gettext('Blockers') ?>: <?= (int)($preflight_report['summary']['blockers'] ?? 0) ?></span>
                    <span class="label label-warning"><?= gettext('Warnings') ?>: <?= (int)($preflight_report['summary']['warnings'] ?? 0) ?></span>
                    <span class="label label-info"><?= gettext('Info') ?>: <?= (int)($preflight_report['summary']['info'] ?? 0) ?></span>
                  </td>
                </tr>
<?php if (!empty($preflight_report['findings'])): ?>
<?php foreach ($preflight_report['findings'] as $finding):
    $sev = $finding['severity'] ?? 'INFO';
    $badge = $sev === 'BLOCKER' ? 'label-danger' : ($sev === 'WARNING' ? 'label-warning' : 'label-info');
?>
                <tr>
                  <td style="width:120px;"><span class="label <?= $badge ?>"><?= html_safe($sev) ?></span></td>
                  <td>
                    <strong><?= html_safe($finding['title'] ?? '') ?></strong><br/>
                    <?= html_safe($finding['message'] ?? '') ?>
<?php if (!empty($finding['remediation'])): ?>
                    <br/><small class="text-muted"><?= html_safe($finding['remediation']) ?></small>
<?php endif ?>
                  </td>
                </tr>
<?php endforeach ?>
<?php else: ?>
                <tr>
                  <td colspan="2"><span class="text-success"><?= gettext('All pre-flight validation checks passed.') ?></span></td>
                </tr>
<?php endif ?>
<?php if (!empty($preflight_missing)): ?>
                <tr>
                  <td colspan="2">
                    <strong><?= gettext('Remap Missing Hardware Interfaces') ?></strong>
                    <p class="text-muted"><?= gettext('Select a hardware NIC present on this system for each missing device in the backup before applying the restore.') ?></p>
                    <table class="table table-condensed table-bordered">
                      <thead>
                        <tr>
                          <th><?= gettext('Missing Backup NIC') ?></th>
                          <th><?= gettext('Referenced By') ?></th>
                          <th><?= gettext('Map to Local Hardware NIC') ?></th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($preflight_missing as $miss):
    $miss_dev = $miss['device'] ?? '';
    $ref_labels = [];
    foreach ($miss['references'] ?? [] as $ref) {
        $ref_labels[] = ($ref['type'] ?? '') === 'interface'
            ? sprintf('%s (%s)', $ref['key'] ?? '', $ref['descr'] ?? '')
            : sprintf('%s %s', $ref['type'] ?? '', $ref['key'] ?? '');
    }
    $selected_target = $preflight_remap[$miss_dev] ?? '';
?>
                        <tr>
                          <td><code><?= html_safe($miss_dev) ?></code></td>
                          <td><?= html_safe(implode(', ', $ref_labels)) ?></td>
                          <td>
                            <select name="remap[<?= html_safe($miss_dev) ?>]" class="form-control">
                              <option value=""><?= html_safe(gettext('-- Do not remap --')) ?></option>
<?php foreach ($preflight_report['interface_remapping']['available_hardware'] ?? [] as $hw):
    $hw_dev = $hw['device'] ?? '';
    $hw_label = $hw_dev;
    if (!empty($hw['mac'])) {
        $hw_label .= ' (' . $hw['mac'] . ')';
    }
    if (!empty($hw['status'])) {
        $hw_label .= ' [' . $hw['status'] . ']';
    }
    if (!empty($hw['descr'])) {
        $hw_label .= ' - ' . $hw['descr'];
    }
?>
                              <option value="<?= html_safe($hw_dev) ?>" <?= $selected_target === $hw_dev ? 'selected="selected"' : '' ?>><?= html_safe($hw_label) ?></option>
<?php endforeach ?>
                            </select>
                          </td>
                        </tr>
<?php endforeach ?>
                      </tbody>
                    </table>
                  </td>
                </tr>
<?php endif ?>
<?php if (empty($preflight_report['can_proceed'])): ?>
                <tr>
                  <td colspan="2">
                    <label class="text-danger" style="font-weight:normal; cursor:pointer;">
                      <input type="checkbox" name="override_blockers" value="1" />
                      <?= gettext('Override remaining pre-flight blockers and apply restore anyway (not recommended unless missing NICs are remapped above).') ?>
                    </label>
                  </td>
                </tr>
<?php endif ?>
                <tr>
                  <td colspan="2">
                    <input name="restore_apply" type="submit" class="btn <?= empty($preflight_report['can_proceed']) ? 'btn-warning' : 'btn-primary' ?>" value="<?= html_safe(!empty($preflight_missing) ? gettext('Apply Remapping & Restore') : gettext('Confirm & Apply Restore')) ?>" />
                    <input name="restore_cancel" type="submit" class="btn btn-default" value="<?= html_safe(gettext('Cancel Restore')) ?>" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
<?php endif ?>
        <section class="col-xs-12">
            <div class="content-box tab-content table-responsive __mb">
                <table class="table table-striped">
                    <div class="content-box tab-content table-responsive __mb">
                    <table class="table table-striped">
                        <tbody>
                            <tr>
                                <td colspan="2"><strong><?= gettext('Backup Count') ?></strong></td>
                            </tr>
                            <tr>
                                <td><input name="backupcount" type="text" size="5" value="<?= html_safe($pconfig['backupcount']) ?>"/></td>
                                <td><?= gettext("Enter the number of older configurations to keep in the local backup cache."); ?></td>
                            </tr>
                            <tr>
                                <td><input name="save" type="submit" class="btn btn-primary" value="<?= html_safe(gettext('Save')) ?>"/></td>
                                <td>
                                    <?= gettext('Be aware of how much space is consumed by backups before adjusting this value.'); ?>
<?php if (count(OPNsense\Core\Config::getInstance()->getBackups(true)) > 0): ?>
                                    <?= gettext('Current space used:') ;?> <?= preg_split('/\s+/', shell_safe('/usr/bin/du %s %s', ['-hs', '/conf/backup']))[0];?>
<?php endif ?>
                                </td>
                            </tr>
                        </tbody>
                </table>
            </div>
        </section>
        <section class="col-xs-12">
          <div class="content-box tab-content table-responsive __mb">
            <table class="table table-striped">
                <tr>
                  <td><strong><?= gettext('Download') ?></strong></td>
                </tr>
                <tr>
                  <td>
                    <input name="donotbackuprrd" type="checkbox" id="dotnotbackuprrd" checked="checked" />
                    <?=gettext("Do not backup RRD data."); ?><br/>
                    <input name="encrypt" type="checkbox" id="encryptconf" />
                    <?=gettext("Encrypt this configuration file."); ?><br/>
                    <div class="hidden table-responsive __mt" id="encrypt_opts">
                      <table class="table table-condensed">
                        <tr>
                          <td><?= gettext('Password') ?></td>
                          <td><input name="encrypt_password" type="password" autocomplete="new-password"/></td>
                        </tr>
                        <tr>
                          <td><?= gettext('Confirmation') ?></td>
                          <td><input name="encrypt_passconf" type="password" autocomplete="new-password"/> </td>
                        </tr>
                      </table>
                    </div>
                  </td>
                </tr>
                <tr>
                  <td>
                    <input name="download" type="submit" class="btn btn-primary" value="<?= html_safe(gettext('Download configuration')) ?>" />
                  </td>
                </tr>
                <tr>
                  <td>
                    <?=gettext("Click this button to download the system configuration in XML format."); ?>
                  </td>
                </tr>
            </table>
          </div>
          <div class="content-box tab-content table-responsive __mb">
            <table class="table table-striped">
                <tr>
                  <td><strong><?= gettext('Restore') ?></strong></td>
                </tr>
                <tr>
                  <td>
                    <?= gettext('Restore areas:') ?>
                    <div>
                      <select name="restorearea[]" id="restorearea" class="selectpicker" multiple="multiple" size="5" title="<?= html_safe(gettext('All (recommended)')) ?>" data-live-search="true" data-size="10">
<?php foreach ($areas as $area => $areaname): ?>
                        <option value="<?= html_safe($area) ?>" <?= in_array($area, $pconfig['restorearea'] ?? []) ? 'selected="selected"' : '' ?>><?= $areaname ?></option>
<?php endforeach ?>
                      </select>
                    </div>
                    <br/><input name="conffile" type="file" id="conffile" /><br/>
                    <input name="rebootafterrestore" type="checkbox" id="rebootafterrestore" <?= !empty($pconfig['rebootafterrestore']) ? 'checked="checked"' : '' ?>/>
                    <?=gettext("Reboot after a successful restore."); ?><br/>
                    <input name="keepconsole" type="checkbox" id="keepconsole" <?= !empty($pconfig['keepconsole']) ? 'checked="checked"' : '' ?>/>
                    <?=gettext("Exclude console settings from import."); ?><br/>
                    <input name="flush_history" type="checkbox" id="flush_history" <?= !empty($pconfig['flush_history']) ? 'checked="checked"' : '' ?>/>
                    <?=gettext("Flush (full) local configuration history."); ?><br/>

                    <input name="decrypt" type="checkbox" id="decryptconf" <?= !empty($pconfig['decrypt']) ? 'checked="checked"' : '' ?>/>
                    <?=gettext("Configuration file is encrypted."); ?>
                    <div class="hidden table-responsive __mt" id="decrypt_opts">
                      <table class="table table-condensed">
                        <tr>
                          <td><?= gettext('Password') ?></td>
                          <td><input name="decrypt_password" type="password" autocomplete="new-password"/></td>
                        </tr>
                      </table>
                    </div>
                  </td>
                </tr>
                <tr>
                  <td>
                    <input name="restore" type="submit" class="btn btn-primary" id="restore" value="<?= html_safe(gettext('Restore configuration')) ?>" />
                  </td>
                </tr>
                <tr>
                  <td>
                    <?=gettext("Open a configuration XML file and click the button below to restore the configuration."); ?><br/>
                  </td>
                </tr>
            </table>
          </div>

<?php
          foreach ($backupFactory->listProviders() as $providerId => $provider):?>
          <div class="content-box tab-content table-responsive __mb">
            <table class="table table-striped opnsense_standard_table_form">
                    <tr>
                        <td colspan="2"><strong><?= $provider['handle']->getName() ?></strong></td>
                    </tr>
<?php
                foreach ($provider['handle']->getConfigurationFields() as $field):
                    $fieldId = $providerId . "_" .$field['name'];?>
                    <tr>
                        <td style="width:22%">
<?php if (!empty($field['help'])): ?>
                            <a id="help_for_<?=$fieldId;?>" href="#" class="showhelp"><i class="fa fa-info-circle"></i></a>
<?php else: ?>
                            <i class="fa fa-info-circle text-muted"></i>
<?php endif ?>
                           <?=$field['label'];?>
                        </td>
                        <td style="width:78%">
<?php if ($field['type'] == 'checkbox'): ?>
                        <input name="<?=$fieldId;?>" type="checkbox" <?=!empty($pconfig[$fieldId]) ? "checked" : "";?> >
<?php elseif ($field['type'] == 'text'): ?>
                        <input name="<?=$fieldId;?>" value="<?=$pconfig[$fieldId];?>" type="text">
<?php elseif ($field['type'] == 'file'): ?>
                        <input name="<?=$fieldId;?>" type="file">
<?php elseif ($field['type'] == 'password'):?>
                        <input name="<?=$fieldId;?>" type="password" autocomplete="new-password" value="<?=$pconfig[$fieldId];?>" />
<?php elseif ($field['type'] == 'textarea'): ?>
                        <textarea name="<?=$fieldId;?>" rows="10"><?=$pconfig[$fieldId];?></textarea>
<?php elseif ($field['type'] == 'passwordarea'): ?>
                        <div id="show-<?=$fieldId;?>-btn">
                          <button onclick="event.preventDefault();show_value('<?= html_safe($fieldId) ?>');" class="btn btn-default"><?= html_safe(gettext('Click to edit')) ?></button>
                        </div>
                        <div id="show-<?=$fieldId;?>-val" style="display:none">
                          <textarea name="<?=$fieldId;?>" rows="10"><?=$pconfig[$fieldId];?></textarea>
                        </div>
<?php elseif ($field['type'] == 'dropdown'): ?>
                        <select name="<?=$fieldId;?>" class="selectpicker" data-live-search="true" data-size="10">
<?php foreach ($field['options'] ?? [] as $value => $label): ?>
                          <option value="<?= html_safe($value) ?>" <?= (string)$value === (string)($pconfig[$fieldId] ?? '') ? 'selected="selected"' : '' ?>><?= html_safe($label) ?></option>
<?php endforeach ?>
                        </select>
<?php endif ?>
                        <div class="hidden" data-for="help_for_<?=$fieldId;?>">
                            <?=!empty($field['help']) ? $field['help'] : "";?>
                        </div>
                        </td>
                    </tr>
<?php
                endforeach;?>

                    <tr>
                        <td></td>
                        <td>
                            <button type="submit" name="setup_<?=$providerId;?>" value="yes" class="btn btn-primary">
                              <?= sprintf(gettext("Setup/Test %s"), $provider['handle']->getName()) ?>
                            </button>
                        </td>
                    </tr>
            </table>
          </div>
<?php
          endforeach;?>
        </section>
      </form>
    </div>
  </div>
</section>

<?php

include("foot.inc");

if ($do_reboot) {
    configd_run('system reboot', true);
}
