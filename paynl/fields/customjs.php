<?php
defined('JPATH_BASE') or die();

jimport('joomla.form.formfield');

class JFormFieldCustomjs extends JFormField
{
  /**
   * Element name
   *
   * @access    protected
   * @var        string
   */
  var $_name = 'Customjs';

  protected function getInput()
  {
    vmJsApi::addJScript( '/plugins/vmpayment/paynl/paynl/assets/js/paynl.js');
    vmJsApi::css('paynl', 'plugins/vmpayment/paynl/paynl/assets/css/');
    
    return '';
  }

 
}