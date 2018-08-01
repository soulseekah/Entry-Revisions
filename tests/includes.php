<?php
function gv_revisions_form_factory_create_and_get( $path ) {
	global $_gf_plugin_dir, $_gv_revisions_tests_dir;

	$gf_data_dir = realpath( $_gf_plugin_dir ) . '/tests/data/forms/';
	$up_up_up = strrev( preg_replace( '#[^/]+#', '..', $gf_data_dir ) );
	$path = $up_up_up . realpath( $_gv_revisions_tests_dir ) . "/data/$path";

	$factory = new GF_UnitTest_Factory();
	$factory->form_filename = $path;

	$form_factory = new GF_UnitTest_Factory_For_Form( $factory );

	return $form_factory->create_and_get();
}
