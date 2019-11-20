function getPaymentProfiles(){
    var serviceId = jQuery('#params_service_id').val();
    var apiToken = jQuery('#params_token_api').val();
    var optionId = jQuery('#params_payNL_optionId').val();

    if(serviceId != '' && apiToken != ''){
        jQuery('#params_payNL_option').html('<option>Loading...</option>');
        jQuery.ajax({
            url: 'https://rest-api.pay.nl/v4/Transaction/getServicePaymentOptions/jsonp/?token='+apiToken+'&serviceId='+serviceId,
            dataType: 'jsonp',
            success: function(data){
                if(data.request.result == 1){                  
                    var options = "";
                    jQuery.each(data.paymentProfiles, function(key, profile){
                        options += "<option value='"+profile.id+"'>"+profile.name+"</option>";
                    });
                    jQuery('#params_payNL_optionList').html(options);
                    jQuery('#params_payNL_optionList').val(optionId);

                    jQuery('#params_payNL_optionList').trigger("liszt:updated");  
                } else {
                    jQuery('#params_payNL_optionList').html('<option>Please check ApiToken and serviceId</option>');
                    jQuery('#params_payNL_optionList').trigger("liszt:updated");
                    alert('Error: '+data.request.errorMessage);
                }
            }
        });
    } 
}
jQuery(document).ready(function(){
    jQuery("#params_payNL_optionId").parent().parent().hide();
    jQuery("#params_payNL_optionList").change(function(evt, params){
        
      jQuery("#params_payNL_optionId").val(jQuery("#params_payNL_optionList").val());
    });
    getPaymentProfiles();
    
    jQuery("#params_service_id").change(function(evt,params){getPaymentProfiles();});
    jQuery("#params_token_api").change(function(evt,params){getPaymentProfiles();});

    jQuery('#params_token_api-lbl').append(' <div class="infoBalloon">?</div>');
    jQuery('#params_service_id-lbl').append(' <div class="infoBalloon">?</div>');

    var translation = jQuery('#params_exchange_url-lbl').data('content');
    jQuery('#params_exchange_url').parent().append('<span class="checkBoxLabel">'+translation+'</span>');

});