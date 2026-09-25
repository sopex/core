<?php

// link menu system
$menu = new OPNsense\Base\Menu\MenuSystem();

/* XXX generating breadcrumbs requires getItems() call */
$menuSystem = $menu->getItems($_SERVER['REQUEST_URI']);
$menuBreadcrumbs = $menu->getBreadcrumbs();
$menuSelectedUrl = $menu->getSelectedUrl();
$menuFavorites = (new OPNsense\Auth\User)->getUserByName($_SESSION['Username'] ?? '')?->menu_favorites->deserialize() ?? [];
$menuSelectedIsFavorite = in_array($menuSelectedUrl, $menuFavorites);

if (isset($extraBreadcrumb)) {
    $menuBreadcrumbs[] = array('name' => $extraBreadcrumb);
}

$pagetitle = html_safe(gentitle(array_reverse($menuBreadcrumbs), ' | '));
$pagetitle .= html_safe(sprintf(' | %s.%s', $config['system']['hostname'], $config['system']['domain']));

?><!doctype html>
<html lang="<?= get_current_lang() ?>" class="no-js">
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

    <title><?= $pagetitle ?></title>

    <!-- include (theme) style -->
    <link rel="stylesheet" type="text/css" href="<?= cache_safe(get_themed_filename('/css/main.css')) ?>">

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

      /** jquery-sortable styles **/
      body.dragging, body.dragging * {
        cursor: move !important;
      }

      .dragged {
        position: absolute;
        opacity: 0.5;
        z-index: 2000;
      }

      ol.example li.placeholder {
        position: relative;
      }
      ol.example li.placeholder:before {
        position: absolute;
      }
    </style>

    <!-- Favicon -->
    <link rel="shortcut icon" href="<?= cache_safe(get_themed_filename('/images/favicon.png')) ?>">

    <!-- legacy browser functions -->
    <script src="<?= cache_safe("/ui/js/polyfills.js") ?>"></script>

    <!-- Stylesheet for fancy select/dropdown -->
    <link rel="stylesheet" type="text/css" href="<?= cache_safe(get_themed_filename("/css/bootstrap-select.css")) ?>">

    <!-- bootstrap dialog -->
    <link rel="stylesheet" type="text/css" href="<?= cache_safe(get_themed_filename("/css/bootstrap-dialog.css")) ?>">

    <!-- Favorites layout -->
    <link rel="stylesheet" type="text/css" href="<?= cache_safe(get_themed_filename("/css/opnsense-favorites.css")) ?>">

    <!-- Font awesome -->
    <link rel="stylesheet" type="text/css" href="<?= cache_safe("/ui/assets/fontawesome/css/all.min.css") ?>">
    <link rel="stylesheet" type="text/css" href="<?= cache_safe("/ui/assets/fontawesome/css/v4-shims.min.css") ?>">

    <!-- nvd3 -->
    <link rel="stylesheet" type="text/css" href="<?= cache_safe(get_themed_filename("/css/nv.d3.css")) ?>">

    <!-- datepicker css -->
    <link rel="stylesheet" type="text/css" href="<?= cache_safe(get_themed_filename("/css/bootstrap-datepicker3.min.css")) ?>">

    <!-- JQuery -->
    <script src="/ui/js/jquery-3.5.1.min.js"></script>

    <!-- datepicker -->
    <script src="<?= cache_safe("/ui/js/bootstrap-datepicker.min.js") ?>"></script>

    <!-- d3 -->
    <script src="<?= cache_safe("/ui/js/d3.min.js") ?>"></script>

    <!-- nvd3 -->
    <script src="<?= cache_safe("/ui/js/nv.d3.min.js") ?>"></script>

    <!-- append some helper functions to integrate into the legacy code -->
    <script src="<?= cache_safe("/javascript/opnsense_legacy.js") ?>"></script>

    <!-- opnsense non legacy helper functions -->
    <script src="<?= cache_safe("/ui/js/opnsense.js") ?>"></script>

    <!-- opnsense non legacy theme support functions -->
    <script src="<?= cache_safe("/ui/js/opnsense_theme.js") ?>"></script>

    <!-- Bootstrap type ahead -->
    <script src="<?= cache_safe("/ui/js/bootstrap3-typeahead.min.js") ?>"></script>

    <script src="<?= cache_safe("/ui/js/opnsense_status.js") ?>"></script>

<?php if (get_themed_filename('/js/theme.js', true)): ?>
    <script src="<?= cache_safe(get_themed_filename('/js/theme.js')) ?>"></script>
<?php endif ?>

    <script>
    //<![CDATA[
    $( document ).ready(function() {
      $('[data-toggle="tooltip"]').tooltip();
      $("input").not("[autocomplete]").attr("autocomplete","off");

      // hide empty menu items
      $('#mainmenu > div > .collapse').not('#Favorites').each(function(){
        // cleanup empty second level menu containers
        $(this).find("div.collapse").each(function(){
          if ($(this).children().length == 0 ) {
              $("#mainmenu").find('[href="#'+$(this).attr('id')+'"]').remove();
              $(this).remove();
          }
        });

        // cleanup empty first level menu items
        if ($(this).children().length == 0) {
          $("#mainmenu").find('[href="#'+$(this).attr('id')+'"]').remove();
        }
      });

      // link showhelp class behavior
      $("a[class='showhelp']").click(function (event) {
        $("*[data-for='" + $(this).attr('id') + "']").toggleClass("hidden show");
        event.preventDefault();
      });

      // handle all help messages show/hide
      $('[id*="show_all_help"]').click(function(event) {
        $('[id*="show_all_help"]').toggleClass("fa-toggle-on fa-toggle-off");
        $('[id*="show_all_help"]').toggleClass("text-success text-danger");
        if ($('[id*="show_all_help"]').hasClass("fa-toggle-on")) {
          if (window.sessionStorage) {
            sessionStorage.setItem('all_help_preset', 1);
          }
          $('[data-for*="help_for"]').addClass("show");
          $('[data-for*="help_for"]').removeClass("hidden");
        } else {
          if (window.sessionStorage) {
            sessionStorage.setItem('all_help_preset', 0);
          }
          $('[data-for*="help_for"]').addClass("hidden");
          $('[data-for*="help_for"]').removeClass("show");
        }
        event.preventDefault();
      });
      if (window.sessionStorage && sessionStorage.getItem('all_help_preset') == 1) {
          // show all help messages when preset was stored
          $('[id*="show_all_help"]').toggleClass("fa-toggle-on fa-toggle-off");
          $('[id*="show_all_help"]').toggleClass("text-success text-danger");
          $('[data-for*="help_for"]').addClass("show");
          $('[data-for*="help_for"]').removeClass("hidden");
      }

      // hide submenu items
      $('#mainmenu .list-group-item').click(function(){
        if($(this).attr('href').substring(0,1) == '#') {
            $('#mainmenu .list-group-item').each(function(){
                if ($(this).attr('aria-expanded') == 'true' && $(this).data('parent') != '#mainmenu') {
                  $("#"+$(this).attr('href').substring(1,999)).collapse('hide');
                }
            });
        }
      });

      $(".list-group-item.active").each(function(){
          var navbar_center = ($( window ).height() - $(".collapse.navbar-collapse").height())/2;
          $('html,aside').scrollTop(($(this).offset().top - navbar_center));
      });

      updateSystemStatus();

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
                              window.location.hash = item.id.substring(item.id.indexOf("#"));
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

      //change search input size on focus() to fit results
      $("#menu_search_box").focus(function(){
          $("#menu_search_box").css('width', '450px');
          $("#system_status").hide();
      });
      $("#menu_search_box").focusout(function(){
          $("#menu_search_box").css('width', '250px');
          $("#system_status").show();
      });
      // convert input form tables for better mobile experience
      hook_stacked_form_tables(".opnsense_standard_table_form");
    });
    //]]>
    </script>
</head>
