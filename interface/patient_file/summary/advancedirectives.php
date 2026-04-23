<?php

/**
 * Advance directives gui.
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @author  Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2017-2018 Brady Miller <brady.g.miller@gmail.com>
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
?>

<html>
<head>
    <title><?php echo xlt('Advance Directives'); ?></title>

    <?php Header::setupHeader(['datetime-picker','opener']); ?>

    <?php
    if (!empty($_POST['form_yesno'])) {
        if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
            CsrfUtils::csrfNotVerified();
        }

        $form_yesno = filter_input(INPUT_POST, 'form_yesno');
        $form_adreviewed = DateToYYYYMMDD(filter_input(INPUT_POST, 'form_adreviewed'));
        sqlQuery("UPDATE patient_data SET completed_ad = ?, ad_reviewed = ? where pid = ?", array($form_yesno,$form_adreviewed,$pid));
        // Close this window and refresh the dashboard display.
        echo "</head><body>\n<script>\n";
        echo " if (!opener.closed && opener.refreshme) opener.refreshme();\n";
        echo " if (!opener.closed && opener.location && opener.location.reload) opener.location.reload();\n";
        echo " dlgclose();\n";
        echo "</script>\n</body>\n</html>\n";
        exit();
    }

    // Initialize form variables
    $form_completedad = '';
    $form_adreviewed = '';

    $sql = "select completed_ad, ad_reviewed from patient_data where pid = ?";
    $myrow = sqlQuery($sql, array($pid));
    if ($myrow && is_array($myrow)) {
        $form_completedad = $myrow['completed_ad'];
        $form_adreviewed = $myrow['ad_reviewed'];
    }
    ?>

    <script>
        function validate(f) {
            if (f.form_adreviewed.value == "") {
                  alert(<?php echo xlj('Please enter a date for Last Reviewed.'); ?>);
                  f.form_adreviewed.focus();
                  return false;
            }
            return true;
        }

        $(function () {
            $("#cancel").click(function() { dlgclose(); });

            $('.datepicker').datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
                <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
            });
        });
    </script>
</head>

<body class="body_top">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <h3><?php echo xlt('Advance Directives'); ?></h3>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <form action='advancedirectives.php' method='post' onsubmit='return validate(this)'>
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    <div class="form-group">
                        <label for="form_yesno"><?php echo xlt('Completed'); ?></label>
                        <?php generate_form_field(array('data_type' => 1,'field_id' => 'yesno','list_id' => 'yesno','empty_title' => 'SKIP'), $form_completedad); ?>
                    </div>
                    <div class="form-group">
                        <label for="form_adreviewed"><?php echo xlt('Last Reviewed'); ?></label>
                        <?php generate_form_field(array('data_type' => 4,'field_id' => 'adreviewed'), oeFormatShortDate($form_adreviewed)); ?>
                    </div>
                    <div class="form-group">
                        <div class="btn-group" role="group">
                            <button type="submit" id="create" class="btn btn-secondary btn-save"><?php echo xla('Save'); ?></button>
                            <button type="button" id="cancel" class="btn btn-link btn-cancel"><?php echo xla('Cancel'); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <hr>
        <div class="row">
            <div class="col-12">
                <?php
                // For now, just show a message that documents can be uploaded
                echo "<p class='text-muted'>" . xlt('Advanced directive documents can be uploaded through the Documents section') . "</p>";
                ?>
            </div>
        </div>
    </div>
</body>
</html>
