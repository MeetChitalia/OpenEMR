<?php

/**
 * This include is just a HTML and JS *fragment* it CANNOT stand alone
 *
 * This file is used to include the capability to have a pop-up DIV in which
 * a user can add a new option to a list box. Primarily this is for the
 * demographics, history, and referral screens. This code could be used elsewhere
 * as long as it had the required supporting HTML and JS
 *
 * REQUIRED to make work:
 *  - jQuery
 *  - form element with the class '.addtolist'
 *  - be used in a file that already includes globals.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// TODO: Convert this to a modal or a dialog

use OpenEMR\Common\Csrf\CsrfUtils;

?>


<!-- hidden DIV used for creating new list items -->
<div id="newlistitem" class="position-absolute bg-light border border-dark" style="display: none; padding: 5px; z-index: 99999; box-shadow: 0 4px 16px rgba(0,0,0,0.12); border-radius: 10px; min-width: 220px; transition: opacity 0.15s; opacity: 0;">
 <button type="button" id="newlistitem_close" aria-label="Close" style="position:absolute;top:6px;right:8px;background:transparent;border:none;font-size:1.3rem;line-height:1;color:#888;cursor:pointer;z-index:100001;">&times;</button>
 <input type="hidden" name="newlistitem_listid" id="newlistitem_listid" value="" />
 <input type="hidden" name="newlistitem_fieldid" id="newlistitem_fieldid" value="" />
 <div id="specificForm"></div>
 <div class="text-center">
  <div style='margin-top: 0.5rem;'>
   <input type='button' class='btn btn-primary' name='newlistitem_submit' id='newlistitem_submit' value='<?php echo xla('Add'); ?>' />
   <input type='button' class='btn btn-secondary' name='newlistitem_cancel' id='newlistitem_cancel' value='<?php echo xla('Cancel'); ?>' />
  </div>
 </div>
</div>


<script>

    // collect the custom state widget flag
    var stateCustomFlag = <?php echo json_encode($GLOBALS['state_custom_addlist_widget']); ?>;

    // generic form for input box
    var generic = "<input type='text' class='form-control' name='newlistitem_value' id='newlistitem_value' size='20' maxlength='50' placeholder='<?php echo xlt('Type to add...'); ?>' />";

    // state form for input box
    var state   = "\
<ul class='m-0 p-0 list-unstyled'>\
 <li class='text-center font-weight-bold' style='font-size:120%;'>\
    <?php echo xlt('Enter new State'); ?></li>\
 <li class='p-0' style='margin-top: 0.5rem;'>\
    <?php echo xlt('Full Name'); ?>:\
  <input type='text' class='form-control' name='newlistitem_value' id='newlistitem_value' size='20' maxlength='50' placeholder='<?php echo xlt('Full Name'); ?>' /><li>\
 <li class='p-0' style='margin-top: 0.5rem;'>\
    <?php echo xlt('Abbreviation'); ?>:\
  <input type='text' class='form-control' name='newlistitem_abbr' id='newlistitem_abbr' size='10' maxlength='50' placeholder='<?php echo xlt('Abbreviation'); ?>' /><li>\
</ul>\
";

// jQuery makes life easier (sometimes)

$(function () {

    /********************************************************/
    /************ List-box modification functions ***********/
    /********************************************************/

    $("#newlistitem").on("keypress", function(evt) { if (evt.keyCode == 13) { SaveNewListItem(this, evt); return false; }});
    $(".addtolist").on("click", function(evt) { AddToList(this, evt); });
    $("#newlistitem_submit").on("click", function(evt) { SaveNewListItem(this, evt); });
    $("#newlistitem_cancel").on("click", function(evt) { CancelAddToList(this, evt); });

    // Override AddToList to use dropdown positioning
    var AddToList = function(btnObj, e) {
        // capture the ID of the list and specific field being modified from the object's ID
        $('#newlistitem_listid').val($(btnObj).attr("id").replace(/^addtolistid_/g, ""));
        $('#newlistitem_fieldid').val($(btnObj).attr("fieldid"));

        // REMOVE previous form
        $("#specificForm").empty();

        // INSERT the selected form
        if ($("#newlistitem_listid").val() == "state" && stateCustomFlag) {
            $("#specificForm").append(state);
            $('#newlistitem_value').val("");
            $('#newlistitem_abbr').val("");
        } else {
            $("#specificForm").append(generic);
            $('#newlistitem_value').val("");
        }

        // Style and position the popup as a dropdown below the button
        var $popup = $('#newlistitem');
        $popup.css({
            display: 'block',
            position: 'absolute',
            zIndex: 99999,
            minWidth: $(btnObj).outerWidth() + 60,
            boxShadow: '0 4px 16px rgba(0,0,0,0.12)',
            borderRadius: '10px',
            background: '#fff',
            padding: '18px 18px 12px 18px',
            border: '1px solid #e2e8f0',
            margin: 0,
            opacity: 0
        });
        // Place below the button
        var rect = btnObj.getBoundingClientRect();
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        var scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
        $popup.css({
            top: rect.bottom + scrollTop + 4,
            left: rect.left + scrollLeft
        });

        // Focus the input
        setTimeout(function() {
            $popup.css('opacity', 1);
            var $input = $('#newlistitem_value');
            $input.focus();
            $input.select();
        }, 100);

        // Re-bind popup button handlers
        $("#newlistitem_submit").off('click').on("click", function(evt) { 
            SaveNewListItem(this, evt); 
        });
        $("#newlistitem_cancel").off('click').on("click", function(evt) { 
            CancelAddToList(this, evt); 
        });
        $("#newlistitem").off('keypress').on("keypress", function(evt) { 
            if (evt.keyCode == 13) { 
                SaveNewListItem(this, evt); 
                return false; 
            }
        });

        // Close on outside click
        $(document).off('mousedown.addlist').on('mousedown.addlist', function(event) {
            if (!$(event.target).closest('#newlistitem, .addtolist').length) {
                $('#newlistitem').hide().css('opacity', 0);
                $(document).off('mousedown.addlist');
            }
        });

        // Close on close button
        $('#newlistitem_close').off('click').on('click', function() {
            $('#newlistitem').hide().css('opacity', 0);
            $(document).off('mousedown.addlist');
        });
    };

    // Always bind the click handler after AddToList is defined
    // Direct binding for existing elements
    $(document).off('click.addtolist').on('click.addtolist', '.addtolist', function(evt) {
        AddToList(this, evt);
    });

    // hide the add-to-list DIV and clear its textbox
    var CancelAddToList = function(btnObj, e) {
        $('#newlistitem').css('opacity', 0);
        setTimeout(function() {
            $('#newlistitem').hide();
            $(document).off('mousedown.addlist');
        }, 150);
    }

    // save the new list item to the given list
    var SaveNewListItem = function(btnObj, e) {
    // VALIDATE the selected form
        //  Don't allow a number as first character
        //  Don't allow illegal characters (' and " for now) - still developing
    //   First, validate fields common to all forms
        if ($("#newlistitem_value").val().match(/^\d/)) {
            alert(<?php echo xlj('List items can not start with a number.'); ?>);
            $("#newlistitem_value").focus();
            return false;
        }
        if ($("#newlistitem_value").val().match(/[\'\"]/)) {
            alert(<?php echo xlj('List items contains illegal character(s).'); ?>);
            $("#newlistitem_value").focus();
            return false;
        }
    //  Second, validate form specific fields
        if (($("#newlistitem_listid").val() == "state") && (stateCustomFlag)) {
            // state forms specific validation
            if ($("#newlistitem_abbr").val().match(/^\d/)) {
                alert(<?php echo xlj('List items can not start with a number.'); ?>);
                $("#newlistitem_abbr").focus();
                return false;
            }
            if ($("#newlistitem_abbr").val().match(/[\'\"]/)) {
                alert(<?php echo xlj('List items contains illegal character(s).'); ?>);
                $("#newlistitem_abbr").focus();
                return false;
            }
        }

        // PROCESS the selected form
        if (($("#newlistitem_listid").val() == "state") && (stateCustomFlag)) {
        // process state form
        //  (note we have collected a title and abbreviation)
        var listid = $("#newlistitem_listid").val();
        var newitem = $('#newlistitem_value').val();
        var newitem_abbr = $('#newlistitem_abbr').val();

    }
    else {
        // process generic form
        //  (note we have only collected the title, so will make
        //   abbreviation same as title)
        var listid = $("#newlistitem_listid").val();
        var newitem = $('#newlistitem_value').val();
        var newitem_abbr = $('#newlistitem_value').val();
    }

        // make the AJAX call to save the new value to the specified list
        // upon returning successfully, refresh the list box and select
        // the new list item
        
        // Debug: Log the parameters being sent
        console.log('Sending AJAX request with parameters:', {
            listid: listid,
            newitem: newitem,
            newitem_abbr: newitem_abbr,
            csrf_token_form: $('input[name="csrf_token_form"]').val()
        });
        
        // Test if the endpoint is accessible
        console.log('Testing AJAX endpoint accessibility...');
        
        $.getJSON("<?php echo $GLOBALS['webroot']; ?>/library/ajax/addlistitem.php",
                    {csrf_token_form: $('input[name="csrf_token_form"]').val(),
                    listid: listid,
             newitem: newitem,
             newitem_abbr: newitem_abbr},
                    function(jsondata, txtresponse) {
                 console.log('AJAX response received:', jsondata);
                 if( jsondata.error == '' ){
              //process each widget with the same list
            $("select.addtolistclass_"+listid).each(function(){
                var pointer = $(this);
                var currentselected = pointer.val();
                var listboxfield = pointer.attr("id");
                var listbox = document.getElementById(listboxfield);
                            while (listbox.options.length > 0) { listbox.options[0] = null; }
                            $.each(jsondata.options, function () {
                    if (listboxfield == $('#newlistitem_fieldid').val()) {
                    // build select for the chosen widget field
                                    listbox.options[listbox.options.length] = new Option(this.title, this.id);
                                    if (this.id == newitem_abbr) {
                        pointer.val(this.id);
                                    }
                }
                else {
                    // build select for the other widgets using the same list
                    listbox.options[listbox.options.length] = new Option(this.title, this.id);
                    if (this.id == currentselected) {
                      pointer.val(currentselected);
                    }
                }
              });
            });
            // now hide the DIV with fade effect
            $('#newlistitem').css('opacity', 0);
            setTimeout(function() {
                $('#newlistitem').hide();
                $(document).off('mousedown.addlist');
            }, 150);
             } else {
               console.error('Server returned error:', jsondata.error);
               alert(jsondata.error);
             }
           }
         ).fail(function(jqXHR, textStatus, errorThrown) {
             console.error('AJAX request failed:', {
                 status: jqXHR.status,
                 statusText: jqXHR.statusText,
                 responseText: jqXHR.responseText,
                 textStatus: textStatus,
                 errorThrown: errorThrown
             });
             alert('Failed to save the new item. Please try again. Error: ' + textStatus);
         });

    }  // end SaveNewListItem

    // let's expose some of these options so that others can use them if they are using the options.inc.php piece
    let optionWidgets = {
        CancelAddToList: CancelAddToList
        ,AddToList: AddToList
        ,SaveNewListItem: SaveNewListItem
    };

    if (!window.oeUI) {
        window.oeUI = {};
    }
    window.oeUI.optionWidgets = optionWidgets;

}); // end jQuery .ready
</script>
