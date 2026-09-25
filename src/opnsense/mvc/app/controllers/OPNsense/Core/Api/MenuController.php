<?php

/**
 *    Copyright (C) 2015 Deciso B.V.
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 *
 */

namespace OPNsense\Core\Api;

use OPNsense\Auth\User;
use OPNsense\Base\ApiControllerBase;
use OPNsense\Base\Menu;
use OPNsense\Core\ACL;
use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;

/**
 * Class MenuController
 * @package OPNsense\Core
 */
class MenuController extends ApiControllerBase
{
    /**
     * traverse menu items and mark user visibility (isVisible true/false)
     * @param array $menuItems menuitems from menu->getItems()
     * @param ACL $acl acl object reference
     */
    private function applyACL(&$menuItems, &$acl)
    {
        foreach ($menuItems as &$menuItem) {
            $menuItem->isVisible = false;
            if (count($menuItem->Children) > 0) {
                $this->applyACL($menuItem->Children, $acl);
                foreach ($menuItem->Children as $subMenuItem) {
                    if ($subMenuItem->isVisible) {
                        $menuItem->isVisible = true;
                        break;
                    }
                }
            } else {
                if (!$acl->isPageAccessible($this->getUserName(), $menuItem->Url)) {
                    $menuItem->isVisible = false;
                } else {
                    $menuItem->isVisible = true;
                }
            }
        }
    }

    /**
     * search visible name of menu nodes
     * @param array $menuItems
     * @param string $query query string
     */
    private function search(&$menuItems, $query)
    {
        foreach ($menuItems as &$menuItem) {
            if (stripos($menuItem->VisibleName, $query) !== false) {
                // match on node
                continue;
            } elseif (count($menuItem->Children) > 0) {
                $this->search($menuItem->Children, $query);
                $menuItem->isVisible = false;
                foreach ($menuItem->Children as $subMenuItem) {
                    if ($subMenuItem->isVisible) {
                        $menuItem->isVisible = true;
                        break;
                    }
                }
            } elseif ($menuItem->isVisible && stripos($menuItem->VisibleName, $query) === false) {
                $menuItem->isVisible = false;
            }
        }
    }

    /**
     * request user context sensitive menu (items)
     * @param string $selected_uri selected uri
     * @return array menu items
     */
    private function getMenu($selected_uri)
    {
        // construct menu and acl and merge collected info
        $menu = new Menu\MenuSystem();
        $acl = new ACL();

        // fetch menu items and apply acl
        $menu_items = $menu->getItems($selected_uri);
        $this->applyACL($menu_items, $acl);
        return $menu_items;
    }

    /**
     * flatten menu structure, only returning visible entries
     * @param $menu_items array containing stdClass objects
     * @return array tree containing simple types
     */
    private function menuToArray($menu_items)
    {
        $result = array();
        foreach ($menu_items as $menu_item) {
            if ($menu_item->isVisible) {
                $new_item = (array) $menu_item;
                $new_item['Children'] = array();
                if (count($menu_item->Children) > 0) {
                    $new_item['Children'] = $this->menuToArray($menu_item->Children);
                }
                $result[] = $new_item;
            }
        }
        return $result;
    }

    /**
     * extract visitable leaves from collection of menu items
     * @param array $menu_items
     * @param array $items result
     */
    private function extractMenuLeaves($menu_items, &$items)
    {
        foreach ($menu_items as $menu_item) {
            if (!isset($menu_item->breadcrumb)) {
                $menu_item->breadcrumb = strip_tags($menu_item->VisibleName);
                $menu_item->page_breadcrumb = '';
                $menu_item->depth = 1;
            }
            if ($menu_item->isVisible) {
                if (count($menu_item->Children) > 0) {
                    foreach ($menu_item->Children as &$submenu) {
                        $submenu->breadcrumb = $menu_item->breadcrumb . ': ' . strip_tags($submenu->VisibleName);
                        $submenu->page_breadcrumb = $menu_item->breadcrumb;
                        $submenu->depth = $menu_item->depth + 1;
                    }
                    $this->extractMenuLeaves($menu_item->Children, $items);
                }

                // only return visible items
                if ($menu_item->Visibility != 'hidden') {
                    unset($menu_item->Children);
                    $items[] = $menu_item;
                }
            }
        }
    }

    /**
     * return menu items for this user
     * @return array
     */
    public function treeAction()
    {
        $selected_uri = $this->request->get("uri", null, null);
        $menu_items = $this->getMenu($selected_uri);
        return $this->menuToArray($menu_items);
    }

    /**
     * get settings index from XML forms, cached on disk
     * @return array
     */
    private function getSettingsIndex()
    {
        $appconfig = new AppConfig();
        $cacheFile = $appconfig->application->tempDir . '/opnsense_settings_search_cache.json';

        if (file_exists($cacheFile) && filemtime($cacheFile) > (time() - 3600)) {
            $data = @json_decode(file_get_contents($cacheFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        $controllersDir = $appconfig->application->controllersDir;
        $settings = [];

        $formUrlMap = [
            'Core/hasyncSettings' => '/ui/core/hasync',
            'Core/snapshot' => '/ui/core/snapshots',
            'Core/tunable' => '/ui/core/tunables',
            'Diagnostics/dns_diagnostics' => '/ui/diagnostics/dns_diagnostics',
            'Diagnostics/netflow_capture' => '/ui/diagnostics/netflow',
            'Diagnostics/packetcapture' => '/ui/diagnostics/packet_capture',
            'Diagnostics/ping' => '/ui/diagnostics/ping',
            'Diagnostics/portprobe' => '/ui/diagnostics/portprobe',
            'Diagnostics/systemhealth' => '/ui/diagnostics/systemhealth',
            'Diagnostics/traceroute' => '/ui/diagnostics/traceroute',
            'Dnsmasq/general' => '/ui/dnsmasq/settings#general',
            'Firewall/geoIPSettings' => '/ui/firewall/alias',
            'Firewall/settings' => '/ui/firewall/settings',
            'Hostdiscovery/general' => '/ui/hostdiscovery/settings',
            'IDS/generalSettings' => '/ui/ids',
            'IPsec/settings' => '/ui/ipsec/connections/settings',
            'Kea/agentSettings' => '/ui/kea/dhcp/ctrl_agent',
            'Kea/ddnsSettings' => '/ui/kea/dhcp/ddns',
            'Kea/generalSettings4' => '/ui/kea/dhcp/v4',
            'Kea/generalSettings6' => '/ui/kea/dhcp/v6',
            'Monit/alerts' => '/ui/monit',
            'Monit/general' => '/ui/monit',
            'Monit/services' => '/ui/monit',
            'Monit/tests' => '/ui/monit',
            'OpenVPN/export_options' => '/ui/openvpn/export',
            'Syslog/local' => '/ui/syslog',
            'Trust/settings' => '/ui/trust/settings',
            'Unbound/acl' => '/ui/unbound/acl',
            'Unbound/advanced' => '/ui/unbound/advanced',
            'Unbound/dnsbl' => '/ui/unbound/dnsbl/index',
            'Unbound/dnsreporting' => '/ui/unbound/overview',
            'Unbound/forwarding' => '/ui/unbound/forward',
            'Unbound/general' => '/ui/unbound/general',
            'Wireguard/general' => '/ui/wireguard/general#instances',
        ];

        $formFiles = glob($controllersDir . '/*/*/forms/*.xml');
        if (is_array($formFiles)) {
            foreach ($formFiles as $formFile) {
                $baseName = basename($formFile);
                if (
                    str_starts_with($baseName, 'dialog') ||
                    str_starts_with($baseName, 'wizard') ||
                    in_array($baseName, ['categoryEdit.xml', 'groupEdit.xml'])
                ) {
                    continue;
                }

                $parts = explode('/', str_replace('\\', '/', $formFile));
                $formName = substr($baseName, 0, -4);
                $module = $parts[count($parts) - 3];
                $key = $module . '/' . $formName;

                $pageUrl = $formUrlMap[$key] ?? null;
                if ($pageUrl === null) {
                    $modLower = strtolower($module);
                    $formLower = strtolower($formName);
                    if (file_exists($controllersDir . "/OPNsense/{$module}/{$formName}Controller.php")) {
                        $pageUrl = "/ui/{$modLower}/{$formLower}";
                    } elseif (file_exists($controllersDir . "/OPNsense/{$module}/IndexController.php")) {
                        $pageUrl = "/ui/{$modLower}";
                    } else {
                        $pageUrl = "/ui/{$modLower}/{$formLower}";
                    }
                }

                $pagePath = parse_url($pageUrl, PHP_URL_PATH);
                $xml = @simplexml_load_file($formFile);
                if ($xml === false) {
                    continue;
                }

                foreach ($xml->xpath('//field') as $field) {
                    $type = (string)$field->type;
                    if (in_array($type, ['header', 'subheader', 'buttons', 'ignore'])) {
                        continue;
                    }
                    $id = (string)$field->id;
                    $label = (string)$field->label;
                    if (empty($id) || empty($label)) {
                        continue;
                    }

                    $help = (string)$field->help;
                    $helpClean = !empty($help) ? trim(preg_replace('/\s+/', ' ', $help)) : '';

                    $settings[] = [
                        'id' => $id,
                        'label' => $label,
                        'help' => $helpClean,
                        'url' => $pageUrl . '#row_' . $id,
                        'path' => $pagePath,
                        'advanced' => ((string)$field->advanced === 'true'),
                    ];
                }
            }
        }

        @file_put_contents($cacheFile, json_encode($settings));
        return $settings;
    }

    /**
     * append matching setting items to search results
     * @param array $items result list of menu and setting items
     * @param array $allAccessibleItems all accessible menu leaves for current user
     * @param string|null $query search query
     */
    private function appendSettingLeaves(&$items, $allAccessibleItems, $query = null)
    {
        $accessibleBreadcrumbs = [];
        foreach ($allAccessibleItems as $item) {
            if (!empty($item->Url)) {
                $path = parse_url($item->Url, PHP_URL_PATH);
                if ($path && !isset($accessibleBreadcrumbs[$path])) {
                    $accessibleBreadcrumbs[$path] = $item->breadcrumb;
                }
            }
        }

        $settings = $this->getSettingsIndex();
        foreach ($settings as $setting) {
            if (!isset($accessibleBreadcrumbs[$setting['path']])) {
                continue;
            }

            $label = gettext($setting['label']);
            $help = !empty($setting['help']) ? gettext($setting['help']) : '';
            $pageBreadcrumb = $accessibleBreadcrumbs[$setting['path']];

            if ($query !== null && $query !== '') {
                if (
                    stripos($label, $query) === false &&
                    stripos($help, $query) === false &&
                    stripos($pageBreadcrumb, $query) === false &&
                    stripos($setting['id'], $query) === false
                ) {
                    continue;
                }
            }

            $settingItem = new \stdClass();
            $settingItem->Url = $setting['url'];
            $settingItem->VisibleName = $label;
            $settingItem->breadcrumb = $pageBreadcrumb . ' > ' . $label;
            $settingItem->page_breadcrumb = $pageBreadcrumb;
            $settingItem->keywords = $help;
            $settingItem->is_setting = true;
            $items[] = $settingItem;
        }
    }

    /**
     * search menu items and specific settings
     * @return array
     */
    public function searchAction()
    {
        $all_menu_items = $this->getMenu(null);
        $all_accessible_items = [];
        $this->extractMenuLeaves($all_menu_items, $all_accessible_items);

        $query = $this->request->get("q", null, null);
        $items = [];
        if ($query != null) {
            // only search when a query is provided, otherwise return all entries
            $menu_items = $this->getMenu(null);
            $this->search($menu_items, $query);
            $this->extractMenuLeaves($menu_items, $items);
        } else {
            $items = $all_accessible_items;
        }

        $this->appendSettingLeaves($items, $all_accessible_items, $query);
        return $items;
    }

    /**
     * set/unset a menu item as favorite
     * @return array
     */
    public function setFavoriteAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }

        $menuUrl = $this->request->getPost('menuUrl', null, null);
        $isFavorite = $this->request->getPost('isFavorite', null, null);

        if ($menuUrl === null || $isFavorite === null) {
            return ['result' => 'failed'];
        }

        // validate that the submitted URL is a visible menu item for this user
        $menuItems = $this->getMenu('/');
        $items = [];
        $this->extractMenuLeaves($menuItems, $items);
        $validUrls = array_column($items, 'Url');
        if (!in_array($menuUrl, $validUrls)) {
            return ['result' => 'failed'];
        }

        /* update user model with current set of valid favorites */
        $user = new User();
        if ($node = $user->getUserByName($this->getUserName())) {
            $favorites = array_values(array_intersect($node->menu_favorites->deserialize(), $validUrls));
            $favorites = array_values(array_filter($favorites, fn($value) => $value !== $menuUrl));
            if (!empty($isFavorite)) {
                $favorites[] = $menuUrl;
            }
            if ($node->menu_favorites->serialize($favorites) && $user->serializeToConfig(false, true)) {
                /* intentionally skipping user-config-readonly check */
                Config::getInstance()->save();
                return ['result' => 'saved'];
            }
        }

        return ['result' => 'failed'];
    }
}
