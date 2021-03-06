<?php
/** 
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @filesource $RCSfile: tcAssignedToUser.php,v $
 * @version $Revision: 1.14 $
 * @modified $Date: 2010/08/26 07:27:48 $  $Author: mx-julian $
 * @author Francisco Mancardi - francisco.mancardi@gmail.com
 * 
 * @internal revisions:
 *  20100826 - Julian - removed redundant version indication
 *  20100825 - Julian - make table collapsible if more than 1 table is shown
 *  20100825 - eloff - BUGID 3711 - Hide platform if not used
 *  20100823 - asimon - refactoring: $table_id
 *  20100822 - franciscom - refactoring - getColumnsDefinition()
 *  20100816 - asimon - if priority is enabled, enable default sorting by that column
 *  20100802 - asimon - BUGID 3647, filtering by build
 *  20100731 - asimon - heavy refactoring, modified to include more parameters and flexibility,
 *                      changed table to ExtJS format
 */
require_once("../../config.inc.php");
require_once("common.php");
require_once("exttable.class.php");

testlinkInitPage($db);
$templateCfg = templateConfiguration();
$user = new tlUser($db);
$names = $user->getNames($db);

$urgencyImportance = config_get('urgencyImportance');
$results_config = config_get('results');

$args=init_args();
if ($args->user_id > 0) {
	$args->user_name = $names[$args->user_id]['login'];
}

$tcase_mgr = new testcase($db);
$tproject_mgr = new testproject($db);
$tproject_info = $tproject_mgr->get_by_id($args->tproject_id);
unset($tproject_mgr);

$gui=new stdClass();
$gui->glueChar = config_get('testcase_cfg')->glue_character;
$gui->tproject_name = $tproject_info['name'];
$gui->warning_msg = '';
$gui->tableSet = null;

$tplan_mgr = new testplan($db);

$l18n = init_labels(array('tcversion_indicator' => null,'goto_testspec' => null, 'version' => null, 
						  'testplan' => null, 'assigned_tc_overview' => null,'testcases_assigned_to_user' => null));

if ($args->show_all_users) {
	$gui->pageTitle=sprintf($l18n['assigned_tc_overview'], $gui->tproject_name);
} else {
	$gui->pageTitle=sprintf($l18n['testcases_assigned_to_user'],$gui->tproject_name, $args->user_name);
}

$priority = array('low' => lang_get('low_priority'),'medium' => lang_get('medium_priority'),'high' => lang_get('high_priority'));

$map_status_code = $results_config['status_code'];
$map_code_status = $results_config['code_status'];
$map_status_label = $results_config['status_label'];
$map_statuscode_css = array();
foreach($map_code_status as $code => $status) {
	if (isset($map_status_label[$status])) {
		$label = $map_status_label[$status];
		$map_statuscode_css[$code] = array();
		$map_statuscode_css[$code]['translation'] = lang_get($label);
		$map_statuscode_css[$code]['css_class'] = $map_code_status[$code] . '_text';
	}
}

// Get all test cases assigned to user without filtering by execution status
$options = new stdClass();
$options->mode = 'full_path';

$filters = array();

// if opened by click on username from page "results by user per build", show all testplans
if (!$args->show_inactive_and_closed) {
	//BUGID 3575: show only assigned test cases for ACTIVE test plans
	$filters['tplan_status'] = 'active';
}

// BUGID 3647
if ($args->build_id) {
	$filters['build_id'] = $args->build_id;
}

$tplan_param = ($args->tplan_id) ? array($args->tplan_id) : testcase::ALL_TESTPLANS;
$gui->resultSet=$tcase_mgr->get_assigned_to_user($args->user_id, $args->tproject_id, 
                                                 $tplan_param, $options, $filters);

$doIt = !is_null($gui->resultSet); 
if( $doIt )
{	
	$tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));

    $tplanSet=array_keys($gui->resultSet);
    $sql="SELECT name,id FROM {$tables['nodes_hierarchy']} " .
         "WHERE id IN (" . implode(',',$tplanSet) . ")";
    $gui->tplanNames=$db->fetchRowsIntoMap($sql,'id');

	$optColumns = array('user' => $args->show_user_column, 'priority' => $args->priority_enabled);

	foreach ($gui->resultSet as $tplan_id => $tcase_set) {

		$show_platforms = !is_null($tplan_mgr->getPlatforms($tplan_id));
		list($columns, $sortByColumn) = getColumnsDefinition($optColumns, $show_platforms);
		$rows = array();

		foreach ($tcase_set as $tcase_platform) {
			foreach ($tcase_platform as $tcase) {
				$current_row = array();
				$tcase_id = $tcase['testcase_id'];
				$tcversion_id = $tcase['tcversion_id'];
				
				if ($args->show_user_column) {
					$current_row[] = htmlspecialchars($names[$tcase['user_id']]['login']);
				}
		
				$current_row[] = htmlspecialchars($tcase['build_name']);
				$current_row[] = htmlspecialchars($tcase['tcase_full_path']);
				
				$current_row[] = "<a href=\"lib/testcases/archiveData.php?edit=testcase&id={$tcase_id}\" " . 
				        		 " title=\"{$l18n['goto_testspec']}\">" .
				        		 htmlspecialchars($tcase['prefix']) . $gui->glueChar . $tcase['tc_external_id'] . 
				        		 ":" . htmlspecialchars($tcase['name']) . 
				        		 sprintf($l18n['tcversion_indicator'],$tcase['version']) 
				        		  . "</a>";

				if ($show_platforms)
				{
					$current_row[] = htmlspecialchars($tcase['platform_name']);
				}
				
				if ($args->priority_enabled) {
					if ($tcase['priority'] >= $urgencyImportance->threshold['high']) {
						$current_row[] = $priority['high'];
					} else if ($tcase['priority'] < $urgencyImportance->threshold['low']) {
						$current_row[] = $priority['low'];
					} else {
						$current_row[] = $priority['medium'];
					}
				}
				
				$last_execution = $tcase_mgr->get_last_execution($tcase_id, $tcversion_id, $tplan_id, 
				                                                 $tcase['build_id'], 
				                                                 $tcase['platform_id']);
				$status = $last_execution[$tcversion_id]['status'];
				if (!$status) {
					$status = $map_status_code['not_run'];
				}
				$current_row[] = '<span class="' . $map_statuscode_css[$status]['css_class'] . '">' . 
				                 $map_statuscode_css[$status]['translation'] . '</span>';

				$current_row[] = htmlspecialchars($tcase['creation_ts']) . 
				                 " (" . get_date_diff($tcase['creation_ts']) . ")";
				
				// add this row to the others
				$rows[] = $current_row;
			}
		}
		
		$table_id = 'tl_' . $args->tproject_id . '_' . $tplan_id . '_table_tc_assignment';
		$table_id .= ($args->show_all_users) ? '_overview' : '_for_user';
		$table_id .= ($args->build_id) ? '_window' : '';

		$matrix = new tlExtTable($columns, $rows, $table_id);
		$matrix->title = $l18n['testplan'] . ": " . htmlspecialchars($gui->tplanNames[$tplan_id]['name']);
		
		// default grouping by first column, which is user for overview, build otherwise
		$matrix->groupByColumn = 0;
		
		// make table collapsible if more than 1 table is shown and surround by frame
		if (count($tplanSet) > 1) {
			$matrix->collapsible = true;
			$matrix->frame = true;
		}
		
		// define toolbar
		$matrix->showToolbar = true;
		$matrix->toolbarExpandCollapseGroupsButton = true;
		$matrix->toolbarShowAllColumnsButton = true;
		$matrix->sortByColumn = $sortByColumn;
		$gui->tableSet[$tplan_id] = $matrix;
	}
}

$smarty = new TLSmarty();
$smarty->assign('gui',$gui);
$smarty->display($templateCfg->template_dir . $templateCfg->default_template);


/**
 * Replacement for the smarty helper function to get that functionality outside of templates.
 * Returns difference between a given date and the current time in days.
 * @author Andreas Simon
 * @param $date
 */
function get_date_diff($date) {
	$date = (is_string($date)) ? strtotime($date) : $date;
	$i = 1/60/60/24;
	return floor((time() - $date) * $i);
}


/**
 * init_args()
 * Get in an object all data that has arrived to page through _REQUEST or _SESSION.
 * If you think this page as a function, you can consider this data arguments (args)
 * to a function call.
 * Using all this data as one object property will help developer to understand
 * if data is received or produced on page.
 *
 * @author franciscom - francisco.mancardi@gmail.com
 * @args - used global coupling accessing $_REQUEST and $_SESSION
 * 
 * @return object of stdClass
 *
 * @since 20090131 - franciscom
 * 
 * @internal revisions:
 *  20100731 - asimon - additional arguments show_all_users and show_inactive_and_closed
 */
function init_args()
{
    $_REQUEST=strings_stripSlashes($_REQUEST);
    $args = new stdClass();
    
    $args->tproject_id = isset($_REQUEST['tproject_id']) ? $_REQUEST['tproject_id'] : 0;
    if( $args->tproject_id == 0)
    {
        $args->tproject_id = isset($_SESSION['testprojectID']) ? $_SESSION['testprojectID'] : 0;
    }

    $args->tplan_id = isset($_REQUEST['tplan_id']) ? $_REQUEST['tplan_id'] : 0;
	$args->build_id = isset($_REQUEST['build_id']) && is_numeric($_REQUEST['build_id']) ? 
	                  $_REQUEST['build_id'] : 0;

	// $args->show_all_users = isset($_REQUEST['show_all_users']) && $_REQUEST['show_all_users'] =! 0 ? true : false;
	$args->show_all_users = (isset($_REQUEST['show_all_users']) && $_REQUEST['show_all_users'] =! 0);
	$args->show_user_column = $args->show_all_users; 

    $args->user_id = isset($_REQUEST['user_id']) ? $_REQUEST['user_id'] : 0;
    if( $args->user_id == 0)
    {
        $args->user_id = isset($_SESSION['userID']) ? $_SESSION['userID'] : 0;
        $args->user_name = $_SESSION['currentUser']->login;
    }	



	if ($args->show_all_users) {
		$args->user_id = TL_USER_ANYBODY;
	}
	
	
	$args->show_inactive_and_closed = isset($_REQUEST['show_inactive_and_closed']) 
	                                  && $_REQUEST['show_inactive_and_closed'] =! 0 ? 
	                                  true : false;

	$args->priority_enabled = $_SESSION['testprojectOptions']->testPriorityEnabled ? true : false;
	
	return $args;
}


/**
 * get Columns definition for table to display
 *
 */
function getColumnsDefinition($optionalColumns, $show_platforms)
{
  	static $labels;
	if( is_null($labels) )
	{
		$lbl2get = array('build' => null,'testsuite' => null,'testcase' => null,'platform' => null,
		       			 'user' => null, 'priority' => null,'status' => null, 'version' => null, 'due_since' => null);
		$labels = init_labels($lbl2get);
	}

	$colDef = array();
	$sortByColNum = 1;
	if ($optionalColumns['user']) 
	{
		$colDef[] = array('title' => $labels['user'], 'width' => 80);
	}
	
	$colDef[] = array('title' => $labels['build'], 'width' => 80);
	$colDef[] = array('title' => $labels['testsuite'], 'width' => 130);
	$colDef[] = array('title' => $labels['testcase'], 'width' => 130);
	if ($show_platforms)
	{
		$colDef[] = array('title' => $labels['platform'], 'width' => 50);
	}
	
	// 20100816 - asimon - if priority is enabled, enable default sorting by that column
	if ($optionalColumns['priority']) 
	{
	  	// if priority is enabled, enable default sorting by that column
	  	$sortByColNum = count($colDef);
		$colDef[] = array('title' => $labels['priority'], 'width' => 50);
	}
	
	$colDef[] = array('title' => $labels['status'], 'width' => 50);
	$colDef[] = array('title' => $labels['due_since'], 'width' => 100);

	return array($colDef, $sortByColNum);
}
?>
