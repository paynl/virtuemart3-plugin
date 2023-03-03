<?php

defined ('_JEXEC') or die();

class JElementCustomjs extends JElement {

	/**
	 * Element name
	 *
	 * @access    protected
	 * @var        string
	 */
	var $_name = 'Customjs';

	function fetchElement ($name, $value, &$node, $control_name) {

		$doc = JFactory::getDocument();
		$doc->addScript(JURI::root(true).'/plugins/vmpayment/paynl/paynl/js/paynl.js');
		$doc->addStyleSheet(JURI::root(true).'/plugins/vmpayment/paynl/paynl/css/paynl.css');

		
		return '';		
	}

}