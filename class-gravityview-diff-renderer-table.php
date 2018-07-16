<?php

class GravityView_Diff_Renderer_Table extends WP_Text_Diff_Renderer_Table {

	protected $_diff_threshold = 0.9;

	/**
	 * @var string Label for the single row
	 */
	protected $_row_label = '';

	/**
	 * @var string When a row is empty, show this as the value
	 */
	protected $_empty_value = '';

	/**
	 * @ignore
	 *
	 * @param string $line HTML-escape the value.
	 * @return string
	 */
	public function deletedLine( $line ) {

		if( '' === $line && '' !== $_empty_value ) {
			$line = $this->_empty_value;
		}

		return "<th scope='row'>{$this->_row_label}</th><td class='diff-deletedline'>{$line}</td>";
	}

}