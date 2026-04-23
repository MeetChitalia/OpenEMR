<?php

/**
 * menu_template.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2016 Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2016 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

?>

<script type="text/html" id="menu-action">
    <li class="main-menu-item">
        <a class="main-menu-link" data-bind="attr: { href: url, tabindex: enabled() ? 0 : -1 }, css: { 'menuDisabled': !enabled() }, click: enabled() ? menuActionClick : null">
            <!-- ko if: icon --><i data-bind="css: icon" class="fa"></i><!-- /ko -->
            <span data-bind="text:label"></span>
        </a>
    </li>
</script>
<script type="text/html" id="menu-header">
    <li class="main-menu-item">
        <button type="button" class="main-menu-header" data-bind="css: { 'menuDisabled': !enabled() }, click: toggleOpen">
            <span style="display: flex; align-items: center;">
                <!-- ko if: icon && !icon.match(/chevron|angle|caret|arrow|fa-chevron|fa-angle|fa-caret|fa-arrow|&#9654;|&#9664;|&#9658;|&#9668;/) --><i data-bind="css: icon" class="fa" style="margin-right:8px;"></i><!-- /ko -->
                <span class="main-menu-label" data-bind="text:label"></span>
            </span>
            <span class="main-menu-arrow" data-bind="css: { open: isOpen }, html: isOpen() ? '&#9660;' : '&#9654;' "></span>
        </button>
        <ul class="main-menu-dropdown" data-bind="foreach: children, visible: isOpen">
            <li data-bind="template: {name:header ? 'menu-header' : 'menu-action', data: $data }"></li>
        </ul>
    </li>
</script>
<script type="text/html" id="menu-template">
    <nav class="sidebar" aria-label="Main Navigation">
        <div class="sidebar-logo">
            <img src="<?php echo $GLOBALS['webroot']; ?>/public/images/JACtrac.jpg" alt="JACtrac" style="max-width:420px;width:auto;height:96px;object-fit:contain;" onerror="this.style.display='none';this.parentNode.insertAdjacentHTML('beforeend','<span style=\'font-size:1.5rem;font-weight:bold;color:#4A90E2\'>JACtrac</span>');">
        </div>
        <ul class="main-menu-list" data-bind="foreach: menu">
            <li data-bind="template: {name:header ? 'menu-header' : 'menu-action', data: $data }"></li>
        </ul>
    </nav>
</script>
