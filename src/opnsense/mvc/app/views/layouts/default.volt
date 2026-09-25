<!doctype html>
<html lang="{{ langcode|safe }}" class="no-js">
  <head>

    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <meta name="robots" content="noindex, nofollow" />
    <meta name="keywords" content="" />
    <meta name="description" content="" />
    <meta name="copyright" content="" />
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1" />
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">

    <title>{{headTitle|default("OPNsense") }} | {{system_hostname}}.{{system_domain}}</title>
    {% set theme_name = ui_theme|default('opnsense') %}

    <!-- Favicon -->
    <link href="{{ cache_safe('/ui/themes/%s/build/images/favicon.png' | format(theme_name)) }}" rel="shortcut icon">

    <!-- css imports -->
    {% for filename in css_files -%}
    <link href="{{ cache_safe(theme_file_or_default(filename, theme_name)) }}" rel="stylesheet">
    {% endfor %}

    <!-- TODO: move to theme style -->
    <style>
      .menu-level-3-item {
        font-size: 90%;
        padding-left: 54px !important;
      }
      .typeahead.dropdown-menu {
        min-width: 320px;
        width: 540px;
        max-width: calc(100vw - 30px);
        max-height: 520px;
        overflow-y: auto;
        overflow-x: hidden;
        right: 0 !important;
        left: auto !important;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.2);
        border-radius: 4px;
        padding: 4px 0;
      }
      .typeahead.dropdown-menu > li > a {
        padding: 7px 14px;
        white-space: normal;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        line-height: 1.35;
      }
      .typeahead.dropdown-menu > li:last-child > a {
        border-bottom: none;
      }
      .typeahead.dropdown-menu > li > a .menu-search-title {
        font-size: 13px;
        font-weight: 600;
        color: inherit;
        display: block;
      }
      .typeahead.dropdown-menu > li > a .menu-search-path {
        font-size: 11px;
        opacity: 0.75;
        margin-top: 2px;
        display: block;
      }
      .typeahead.dropdown-menu > li > a .menu-search-help {
        font-size: 11px;
        opacity: 0.8;
        margin-top: 2px;
        display: block;
      }
      .typeahead.dropdown-menu > li.active > a,
      .typeahead.dropdown-menu > li > a:hover {
        color: #fff !important;
      }
      .typeahead.dropdown-menu > li.active > a .text-muted,
      .typeahead.dropdown-menu > li.active > a .menu-search-path,
      .typeahead.dropdown-menu > li.active > a .menu-search-help,
      .typeahead.dropdown-menu > li.active > a i,
      .typeahead.dropdown-menu > li > a:hover .text-muted,
      .typeahead.dropdown-menu > li > a:hover .menu-search-path,
      .typeahead.dropdown-menu > li > a:hover .menu-search-help,
      .typeahead.dropdown-menu > li > a:hover i {
        color: #fff !important;
        opacity: 0.9;
      }
    </style>

    <!-- script imports -->
    {% for filename in javascript_files -%}
    <script src="{{ cache_safe(filename) }}"></script>
    {% endfor %}

    <!-- theme JS -->
    <script src="{{ cache_safe(theme_file_or_default('/js/theme.js', theme_name)) }}"></script>

    <script>
            // setup default scripting after page loading.
            $( document ).ready(function() {
                // hook into jquery ajax requests to ensure csrf handling.
                $.ajaxSetup({
                    'beforeSend': function(xhr) {
                        xhr.setRequestHeader("X-CSRFToken", "{{ csrf_token }}" );
                    }
                });
                // propagate ajax error messages
                $( document ).ajaxError(function( event, request, ajaxSettings ) {
                    const filter_uris = ['/api/core/firmware/upgradestatus'];
                    if (request.responseJSON != undefined && request.responseJSON.errorMessage != undefined) {
                        const url = new URL(ajaxSettings.url, window.location.origin);
                        if (filter_uris.includes(url.pathname)) {
                            return; // prevent errors on specific endpoints, specified above
                        } else if ($("#opnsense-generic-error-dialog").is(':visible')) {
                            return; // prevent error windows from constantly popping up.
                        }
                        let error_type = BootstrapDialog.TYPE_DANGER;
                        switch (request.responseJSON.errorLevel ?? '') {
                            case 'warning':
                                error_type = BootstrapDialog.TYPE_WARNING
                                break;
                            case 'info':
                                error_type = BootstrapDialog.TYPE_INFO
                                break;
                        }
                        BootstrapDialog.show({
                            id: 'opnsense-generic-error-dialog',
                            type: error_type,
                            title: request.responseJSON.errorTitle,
                            message:request.responseJSON.errorMessage,
                            buttons: [{
                                label: '{{ lang._('Close') }}',
                                action: function(dialogItself){
                                    dialogItself.close();
                                }
                            }]
                        });
                    }
                });

                // hide empty menu items
                $('#mainmenu > div > .collapse').not('#Favorites').each(function () {
                    // cleanup empty second level menu containers
                    $(this).find("div.collapse").each(function () {
                        if ($(this).children().length == 0) {
                            $("#mainmenu").find('[href="#' + $(this).attr('id') + '"]').remove();
                            $(this).remove();
                        }
                    });

                    // cleanup empty first level menu items
                    if ($(this).children().length == 0) {
                        $("#mainmenu").find('[href="#' + $(this).attr('id') + '"]').remove();
                    }
                });
                // hide submenu items
                $('#mainmenu .list-group-item').click(function(){
                    if($(this).attr('href').substring(0,1) == '#') {
                        $('#mainmenu .list-group-item').each(function(){
                            if ($(this).attr('aria-expanded') == 'true'  && $(this).data('parent') != '#mainmenu') {
                                $("#"+$(this).attr('href').substring(1,999)).collapse('hide');
                            }
                        });
                    }
                });

                initFormHelpUI();
                initFormAdvancedUI();
                initFormSearchUI();
                initSettingTargetUI();
                addMultiSelectClearUI();
                initGlobalOpenShortcuts();

                updateSystemStatus();

                // Register collapsible table headers
                $('.table').on('click', 'thead', function(event) {
                    let collapse = $(event.currentTarget).next();
                    let id = collapse.attr('class');
                    if (collapse != undefined && id !== undefined && id === "collapsible") {
                        let icon = $('> tr > th > div > i', event.currentTarget);
                        if (collapse.is(':hidden')) {
                            collapse.toggle(0);
                            collapse.css('display', '');
                            icon.toggleClass("fa-angle-right fa-angle-down");
                            return;
                        }
                        icon.toggleClass("fa-angle-down fa-angle-right");
                        $('> tr > td', collapse).toggle(0);
                    }
                });

                // hook in live menu search
                $.ajax("/api/core/menu/search/", {
                    type: 'get',
                    cache: false,
                    dataType: "json",
                    data: {},
                    error : function (jqXHR, textStatus, errorThrown) {
                        console.log('menu.search : ' +errorThrown);
                    },
                    success: function (data) {
                        var menusearch_items = [];
                        $.each(data, function(idx, menu_item){
                            if (menu_item.Url != "") {
                                var isSetting = menu_item.is_setting || false;
                                var title = menu_item.VisibleName || "";
                                var path = menu_item.page_breadcrumb || "";
                                if (!isSetting && !path && menu_item.breadcrumb) {
                                    var lastSep = menu_item.breadcrumb.lastIndexOf(': ');
                                    if (lastSep > -1) {
                                        title = menu_item.breadcrumb.substring(lastSep + 2);
                                        path = menu_item.breadcrumb.substring(0, lastSep);
                                    } else {
                                        title = menu_item.breadcrumb;
                                    }
                                }

                                menusearch_items.push({
                                    id: $('<div/>').html(menu_item.Url).text(),
                                    name: $("<div/>").html(title).text(),
                                    path: $("<div/>").html(path.replace(/: /g, ' › ')).text(),
                                    full_breadcrumb: $("<div/>").html(menu_item.breadcrumb || '').text(),
                                    keywords: menu_item.keywords || '',
                                    is_setting: isSetting
                                });
                            }
                        });
                        $("#menu_search_box").typeahead({
                            source: menusearch_items,
                            items: 10,
                            displayText: function(item) {
                                return item.full_breadcrumb || item.name;
                            },
                            matcher: function (item) {
                                var q = this.query.trim();
                                if (q === "") {
                                    return false;
                                }
                                var terms = q.toLowerCase().split(/\s+/).filter(Boolean);
                                if (terms.length === 0) {
                                    return false;
                                }
                                var searchable = (item.name + ' ' + (item.path || '') + ' ' + (item.keywords || '')).toLowerCase();
                                for (var i = 0; i < terms.length; i++) {
                                    if (searchable.indexOf(terms[i]) === -1) {
                                        return false;
                                    }
                                }
                                return true;
                            },
                            render: function (items) {
                                var that = this;
                                var self = this;
                                var activeFound = false;

                                function highlightTerms(text, query) {
                                    if (!text) return '';
                                    var terms = query.trim().split(/\s+/).filter(Boolean);
                                    var escapedText = $('<div/>').text(text).html();
                                    if (terms.length === 0) return escapedText;

                                    var escapedTerms = terms.map(function(t) {
                                        return t.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
                                    });
                                    var regex = new RegExp('(' + escapedTerms.join('|') + ')', 'gi');
                                    return escapedText.replace(regex, '<strong>$1</strong>');
                                }

                                items = $(items).map(function (i, item) {
                                    var text = self.displayText(item);
                                    i = $(that.options.item).data('value', item);

                                    var iconHtml = item.is_setting
                                        ? '<i class="fa fa-cog fa-fw text-muted" aria-hidden="true"></i> '
                                        : '<i class="fa fa-folder-open-o fa-fw text-muted" aria-hidden="true"></i> ';

                                    var titleHtml = '<span class="menu-search-title">' + iconHtml + highlightTerms(item.name, that.query) + '</span>';
                                    var pathHtml = item.path ? '<span class="menu-search-path text-muted">' + highlightTerms(item.path, that.query) + '</span>' : '';

                                    var helpHtml = '';
                                    if (item.keywords) {
                                        var terms = that.query.trim().split(/\s+/).filter(Boolean);
                                        var kwLower = item.keywords.toLowerCase();
                                        var titleLower = item.name.toLowerCase();
                                        var pathLower = (item.path || '').toLowerCase();
                                        var matchIdx = -1;

                                        for (var t = 0; t < terms.length; t++) {
                                            var term = terms[t].toLowerCase();
                                            var idx = kwLower.indexOf(term);
                                            if (idx > -1 && titleLower.indexOf(term) === -1 && pathLower.indexOf(term) === -1) {
                                                if (matchIdx === -1 || idx < matchIdx) {
                                                    matchIdx = idx;
                                                }
                                            }
                                        }

                                        if (matchIdx > -1) {
                                            var start = Math.max(0, matchIdx - 20);
                                            var end = Math.min(item.keywords.length, matchIdx + 50);
                                            var snippet = (start > 0 ? '…' : '') + item.keywords.substring(start, end).trim() + (end < item.keywords.length ? '…' : '');
                                            helpHtml = '<span class="menu-search-help text-muted"><small><i class="fa fa-info-circle fa-fw" aria-hidden="true"></i> ' + highlightTerms(snippet, that.query) + '</small></span>';
                                        }
                                    }

                                    i.find('a').html(titleHtml + pathHtml + helpHtml);
                                    if (text == self.$element.val()) {
                                        i.addClass('active');
                                        self.$element.data('active', item);
                                        activeFound = true;
                                    }
                                    return i[0];
                                });

                                if (this.autoSelect && !activeFound) {
                                    items.first().addClass('active');
                                    this.$element.data('active', items.first().data('value'));
                                }
                                this.$menu.html(items);
                                return this;
                            },
                            afterSelect: function(item){
                                var targetBase = item.id.split("#")[0];
                                var currentBase = window.location.href.split("#")[0];
                                if (currentBase.indexOf(targetBase) > -1 || window.location.pathname === targetBase) {
                                    if (item.id.indexOf("#") > -1) {
                                        var hash = item.id.substring(item.id.indexOf("#"));
                                        if (window.location.hash !== hash) {
                                            window.location.hash = hash;
                                        } else if (typeof highlightSettingTargetUI === 'function') {
                                            highlightSettingTargetUI(hash);
                                        }
                                        return;
                                    }
                                    window.location.reload();
                                } else {
                                    window.location.href = item.id;
                                }
                            }
                        });
                    }
                });

                // change search input size on focus() to fit results
                $("#menu_search_box").focus(function(){
                    $("#menu_search_box").css('width', '450px');
                    $("#system_status").hide();
                });
                $("#menu_search_box").focusout(function(){
                    $("#menu_search_box").css('width', '250px');
                    $("#system_status").show();
                });
                // enable bootstrap tooltips
                $('body').tooltip({
                    selector: '[data-toggle="tooltip"]',
                    container: 'body'
                });

                // fix menu scroll position on page load
                $(".list-group-item.active").each(function(){
                    var navbar_center = ($( window ).height() - $(".collapse.navbar-collapse").height())/2;
                    $('html,aside').scrollTop(($(this).offset().top - navbar_center));
                });
                // prevent form submits on mvc pages
                $("form").submit(function() {
                    return false;
                });

                /* overwrite clipboard paste behavior and trim before paste */
                $("input").on('paste', function(e) {
                    let clipboard_data = e.originalEvent.clipboardData.getData("text/plain").trim();
                    if (clipboard_data.length > 0) {
                        e.preventDefault();
                        document.execCommand('insertText', false, clipboard_data);
                    }
                });

            });
        </script>
  </head>
  <body>
  <header class="page-head">
    <nav class="navbar navbar-default">
      <div class="container-fluid">
        <div class="navbar-header">
          <a class="navbar-brand" href="/">
            {% if file_exists(["/usr/local/opnsense/www/themes/",theme_name,"/build/images/default-logo.svg"]|join("")) %}
                <img class="brand-logo" src="{{ cache_safe('/ui/themes/%s/build/images/default-logo.svg' | format(theme_name)) }}" height="30" alt="logo"/>
            {% else %}
                <img class="brand-logo" src="{{ cache_safe('/ui/themes/%s/build/images/default-logo.png' | format(theme_name)) }}" height="30" alt="logo"/>
            {% endif %}
            {% if file_exists(["/usr/local/opnsense/www/themes/",theme_name,"/build/images/icon-logo.svg"]|join("")) %}
                <img class="brand-icon" src="{{ cache_safe('/ui/themes/%s/build/images/icon-logo.svg' | format(theme_name)) }}" height="30" alt="icon"/>
            {% else %}
                <img class="brand-icon" src="{{ cache_safe('/ui/themes/%s/build/images/icon-logo.png' | format(theme_name)) }}" height="30" alt="icon"/>
            {% endif %}
          </a>
          <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navigation">
            <span class="sr-only">{{ lang._('Toggle navigation') }}</span>
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
          </button>
        </div>
        <button class="toggle-sidebar" data-toggle="tooltip right" title="{{ lang._('Toggle sidebar') }}" style="display:none;"><i class="fa fa-chevron-left"></i></button>
        <div class="collapse navbar-collapse">
          <ul class="nav navbar-nav navbar-right">
            <li id="menu_messages">
              <span class="navbar-text">{{session_username}}@{{system_hostname}}.{{system_domain}}</span>
            </li>
            <li>
              <span class="navbar-text" style="margin-left: 0">
                <i id="system_status" data-toggle="tooltip left" title="{{ lang._('Show system status') }}" style="cursor:pointer" class="fa fa-circle text-muted"></i>
              </span>
            </li>
            <li>
              <form class="navbar-form" role="search">
                <div class="input-group">
                  <div class="input-group-addon"><i class="fa fa-search"></i></div>
                  <input type="text" style="width: 250px;" class="form-control" tabindex="1" data-provide="typeahead" id="menu_search_box" autocomplete="off">
                </div>
              </form>
            </li>
          </ul>
        </div>
      </div>
    </nav>
  </header>

  <main class="page-content col-sm-9 col-sm-push-3 col-lg-10 col-lg-push-2">
      <!-- menu system -->
      {{ partial("layout_partials/base_menu_system") }}
      <!-- menu favorites -->
      <span id="favorites-config" data-add-text="{{ lang._('Add Favorite') }}" data-remove-text="{{ lang._('Remove Favorite') }}" data-favorites="{{ menuFavorites | safe }}"></span>
      <div class="row">
        <!-- page header -->
        <header class="page-content-head">
          <div class="container-fluid">
            <ul class="list-inline">
              <li><h1{% if menuSelectedUrl is defined and menuSelectedUrl != '' %} tabindex="-1"{% endif %}>{{title | default("")}}{% if menuSelectedUrl is defined and menuSelectedUrl != '' %}<i class="menu-favorite-star {% if menuSelectedIsFavorite %}fa fa-star{% else %}fa fa-star-o{% endif %}" data-menu-url="{{ menuSelectedUrl | safe }}" data-toggle="tooltip" data-container="body" data-placement="bottom" title="{% if menuSelectedIsFavorite %}{{ lang._('Remove Favorite') }}{% else %}{{ lang._('Add Favorite') }}{% endif %}"></i>{% endif %}</h1></li>
              <li class="btn-group-container" id="service_status_container"></li>
            </ul>
          </div>
        </header>

        <!-- page content -->
        <section class="page-content-main">
          <div class="container-fluid">
            <div class="row">
                <!-- notification banner dynamically inserted here (opnsense_status.js) -->

                <section class="col-xs-12">
                    <div id="messageregion"></div>
                        {{ content() }}
                </section>
            </div>
          </div>
        </section>
        <!-- page footer -->
        <footer class="page-foot">
          <div class="container-fluid">
            <a target="_blank" href="{{ product_website }}">{{ product_name }}</a> (c) {{ product_copyright_years }}
            <a target="_blank" href="{{ product_copyright_url }}">{{ product_copyright_owner }}</a>
          </div>
        </footer>
      </div>
    </main>

    <!-- dialog "wait for (service) action" -->
    <div class="modal fade" id="OPNsenseStdWaitDialog" tabindex="-1" data-backdrop="static" data-keyboard="false">
      <div class="modal-backdrop fade in"></div>
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-body">
            <p><strong>{{ lang._('Please wait...') }}</strong></p>
            <div class="progress">
               <div class="progress-bar progress-bar-info progress-bar-striped active" role="progressbar" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100" style="width:100%"></div>
             </div>
          </div>
        </div>
      </div>
    </div>

    <script>
        /* hook translations  when all JS modules are loaded*/
        $.extend(jQuery.fn.UIBootgrid.translations, {
            add: "{{ lang._('Add') }}",
            deleteSelected: "{{ lang._('Delete selected') }}",
            enableSelected: "{{ lang._('Enable selected') }}",
            disableSelected: "{{ lang._('Disable selected') }}",
            edit: "{{ lang._('Edit') }}",
            disable: "{{ lang._('Disable') }}",
            enable: "{{ lang._('Enable') }}",
            delete: "{{ lang._('Delete') }}",
            info: "{{ lang._('Info') }}",
            clone: "{{ lang._('Clone') }}",
            all: "{{ lang._('All') }}",
            search: "{{ lang._('Search') }}",
            removeWarning: "{{ lang._('Remove selected item(s)?') }}",
            noresultsfound: "{{ lang._('No results found') }}",
            refresh: "{{ lang._('Refresh') }}",
            infosTotal: "{{ lang._('Showing %s to %s of %s entries') | format('{{ctx.start}}','{{ctx.end}}','{{ctx.totalRows}}') }}",
            infos: "{{ lang._('Showing %s to %s') | format('{{ctx.start}}','{{ctx.end}}') }}",
            resetGrid: "{{ lang._('Reset grid layout') }}",
            searchColumns: "{{ lang._('Search columns') }}",
            expand: "{{ lang._('Click to expand/collapse cell') }}",
            maximizeGrid: "{{ lang._('Maximize grid') }}",
            minimizeGrid: "{{ lang._('Minimize grid') }}"
        });

        $.fn.selectpicker.defaults = $.fn.selectpicker.defaults || {};
        $.extend($.fn.selectpicker.defaults, {noneSelectedText: '{{ lang._('Nothing selected') }}'});
    </script>

  </body>
</html>
