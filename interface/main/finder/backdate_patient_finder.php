<?php

require_once(dirname(__FILE__) . "/../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('patients', 'demo', '', ['read'])) {
    die(xlt('Unauthorized'));
}

$csrfToken = CsrfUtils::collectCsrfToken();
$pageSize = empty($GLOBALS['gbl_pt_list_page_size']) ? 10 : (int) $GLOBALS['gbl_pt_list_page_size'];
if ($pageSize < 1) {
    $pageSize = 10;
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['jquery', 'bootstrap', 'font-awesome']); ?>
    <title><?php echo xlt('BackDate Transactions'); ?></title>
    <style>
        body {
            margin: 0;
            background: #f7f8fb;
            color: #1f2937;
            font-family: Arial, sans-serif;
        }

        .page-wrap {
            max-width: 1480px;
            margin: 0 auto;
            padding: 28px;
        }

        .page-title {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 800;
            color: #1f2937;
        }

        .page-subtitle {
            margin: 0 0 24px;
            color: #6b7280;
            font-size: 16px;
            font-weight: 500;
        }

        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }

        .search-bar {
            display: flex;
            gap: 14px;
            align-items: center;
            padding: 20px;
            margin-bottom: 22px;
        }

        .search-input-wrap {
            position: relative;
            flex: 1 1 auto;
        }

        .search-input-wrap i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .search-input {
            width: 100%;
            height: 46px;
            padding: 0 16px 0 42px;
            border: 1px solid #dbe1ea;
            border-radius: 12px;
            font-size: 16px;
            outline: none;
        }

        .search-input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.16);
        }

        .btn-clear {
            height: 46px;
            padding: 0 18px;
            border: 1px solid #dbe1ea;
            border-radius: 12px;
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            cursor: pointer;
        }

        .table-card {
            overflow: hidden;
        }

        .table-head,
        .table-row {
            display: grid;
            grid-template-columns: minmax(260px, 2fr) minmax(170px, 1fr) minmax(150px, 1fr) minmax(140px, 0.8fr) minmax(140px, 0.8fr);
            gap: 18px;
            align-items: center;
        }

        .table-head {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 800;
            color: #334155;
        }

        .table-row {
            padding: 14px 20px;
            border-bottom: 1px solid #eef2f7;
        }

        .table-row:last-child {
            border-bottom: 0;
        }

        .name-cell {
            font-weight: 700;
            color: #1f2937;
        }

        .backdate-btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            min-height: 34px;
            padding: 0 14px;
            border: 0;
            border-radius: 8px;
            background: #c2185b;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
        }

        .table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
            background: #fff;
        }

        .footer-left {
            color: #475569;
            font-weight: 600;
        }

        .footer-right {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .pager-btn {
            min-width: 94px;
            height: 40px;
            border: 1px solid #dbe1ea;
            border-radius: 10px;
            background: #fff;
            color: #334155;
            font-weight: 700;
            cursor: pointer;
        }

        .pager-btn[disabled] {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .empty-state,
        .loading-state,
        .error-state {
            padding: 28px 20px;
            text-align: center;
            color: #64748b;
            font-weight: 600;
        }

        @media (max-width: 960px) {
            .table-head {
                display: none;
            }

            .table-row {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .table-row > div::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 2px;
                color: #64748b;
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.03em;
            }

            .table-footer,
            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="page-wrap">
        <h1 class="page-title"><?php echo xlt('Patients'); ?></h1>
        <p class="page-subtitle"><?php echo xlt('List of all patients for BackDate Transactions'); ?></p>

        <div class="card search-bar">
            <div class="search-input-wrap">
                <i class="fa fa-search"></i>
                <input
                    type="text"
                    id="backdate_patient_search"
                    class="search-input"
                    placeholder="<?php echo attr(xl('Search patients by name, phone, DOB, or ID')); ?>"
                    autocomplete="off"
                />
            </div>
            <button type="button" id="clear_backdate_search" class="btn-clear"><?php echo xlt('Clear'); ?></button>
        </div>

        <div class="card table-card">
            <div class="table-head">
                <div><?php echo xlt('Full Name'); ?></div>
                <div><?php echo xlt('Mobile Phone'); ?></div>
                <div><?php echo xlt('Date of Birth'); ?></div>
                <div><?php echo xlt('External ID'); ?></div>
                <div><?php echo xlt('BackDate'); ?></div>
            </div>
            <div id="backdate_patient_rows">
                <div class="loading-state"><?php echo xlt('Loading patients...'); ?></div>
            </div>
            <div class="table-footer">
                <div id="backdate_patient_summary" class="footer-left"></div>
                <div class="footer-right">
                    <button type="button" id="backdate_prev" class="pager-btn"><?php echo xlt('Previous'); ?></button>
                    <button type="button" id="backdate_next" class="pager-btn"><?php echo xlt('Next'); ?></button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            var csrfToken = <?php echo js_url($csrfToken); ?>;
            var webroot = <?php echo js_url($GLOBALS['webroot']); ?>;
            var pageSize = <?php echo (int) $pageSize; ?>;
            var currentPage = 1;
            var totalRows = 0;
            var currentSearch = '';
            var searchTimer = null;

            function escapeHtml(value) {
                return $('<div>').text(value || '').html();
            }

            function renderRows(rows) {
                var $container = $('#backdate_patient_rows');
                if (!rows.length) {
                    $container.html('<div class="empty-state"><?php echo attr_js(xl('No matching patients found.')); ?></div>');
                    return;
                }

                var html = '';
                rows.forEach(function(row) {
                    var url = webroot + '/interface/pos/backdate_pos_screen.php?pid=' + encodeURIComponent(row.pid);
                    html += '<div class="table-row">';
                    html += '<div class="name-cell" data-label="<?php echo attr_js(xl('Full Name')); ?>">' + escapeHtml(row.name) + '</div>';
                    html += '<div data-label="<?php echo attr_js(xl('Mobile Phone')); ?>">' + escapeHtml(row.phone) + '</div>';
                    html += '<div data-label="<?php echo attr_js(xl('Date of Birth')); ?>">' + escapeHtml(row.dob) + '</div>';
                    html += '<div data-label="<?php echo attr_js(xl('External ID')); ?>">' + escapeHtml(row.external_id) + '</div>';
                    html += '<div data-label="<?php echo attr_js(xl('BackDate')); ?>"><a class="backdate-btn" href="' + url + '"><i class="fa fa-calendar"></i> <?php echo attr_js(xl('Backdate')); ?></a></div>';
                    html += '</div>';
                });
                $container.html(html);
            }

            function updateFooter() {
                var start = totalRows ? ((currentPage - 1) * pageSize) + 1 : 0;
                var end = Math.min(currentPage * pageSize, totalRows);
                $('#backdate_patient_summary').text('Showing ' + start + ' to ' + end + ' of ' + totalRows + ' entries');
                $('#backdate_prev').prop('disabled', currentPage <= 1);
                $('#backdate_next').prop('disabled', end >= totalRows);
            }

            function renderError() {
                $('#backdate_patient_rows').html('<div class="error-state"><?php echo attr_js(xl('Unable to load patients right now.')); ?></div>');
                $('#backdate_patient_summary').text('');
                $('#backdate_prev').prop('disabled', true);
                $('#backdate_next').prop('disabled', true);
            }

            function loadPatients() {
                $('#backdate_patient_rows').html('<div class="loading-state"><?php echo attr_js(xl('Loading patients...')); ?></div>');

                $.ajax({
                    url: 'backdate_patient_ajax.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        csrf_token_form: csrfToken,
                        q: currentSearch,
                        page: currentPage,
                        page_size: pageSize
                    },
                    success: function(response) {
                        if (!response || !response.success) {
                            renderError();
                            return;
                        }

                        totalRows = parseInt(response.total || 0, 10);
                        renderRows(Array.isArray(response.rows) ? response.rows : []);
                        updateFooter();
                    },
                    error: function() {
                        renderError();
                    }
                });
            }

            $('#backdate_patient_search').on('input', function() {
                currentSearch = $.trim($(this).val() || '');
                currentPage = 1;
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(loadPatients, 250);
            });

            $('#clear_backdate_search').on('click', function() {
                $('#backdate_patient_search').val('');
                currentSearch = '';
                currentPage = 1;
                loadPatients();
                $('#backdate_patient_search').focus();
            });

            $('#backdate_prev').on('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    loadPatients();
                }
            });

            $('#backdate_next').on('click', function() {
                if (currentPage * pageSize < totalRows) {
                    currentPage++;
                    loadPatients();
                }
            });

            loadPatients();
            $('#backdate_patient_search').focus();
        })();
    </script>
</body>
</html>
