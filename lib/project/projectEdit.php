<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later. 
 *
 * test project management
 *
 * @filesource	projectEdit.php
 * @package 	TestLink
 * @author 		Martin Havlat
 * @copyright 	2007-2012, TestLink community 
 * @link 		http://www.teamst.org/index.php
 *
 *
 * @internal revisions
 * @since 1.9.4
 * 20120731 - kinow - TICKET 4977: CSRF token
 * 20120311 - franciscom - TICKET 4904: integrate with ITS on test project basis
 *
 */

require_once('../../config.inc.php');
require_once('common.php');
require_once("web_editor.php");
$editorCfg = getWebEditorCfg('testproject');
require_once(require_web_editor($editorCfg['type']));

testlinkInitPage($db,true,false,"checkRights");

$gui_cfg = config_get('gui');
$templateCfg = templateConfiguration();

$session_tproject_id = isset($_SESSION['testprojectID']) ? $_SESSION['testprojectID'] : 0;
$template = null;


$ui = new stdClass();
$ui->doActionValue = '';
$ui->buttonValue = '';
$ui->caption = '';
$ui->main_descr = lang_get('title_testproject_management');

$user_feedback = '';
$reloadType = 'none';

$tproject_mgr = new testproject($db);
$args = init_args($tproject_mgr, $_REQUEST, $session_tproject_id);

$gui = initializeGui($db,$args);
$of = web_editor('notes',$_SESSION['basehref'],$editorCfg) ;
$status_ok = 1;

switch($args->doAction)
{
    case 'create':
    	$template = $templateCfg->default_template;
      	$ui = create($args,$tproject_mgr);
      	$gui->testprojects = $ui->testprojects;
    	break;

    case 'edit':
    	$template = $templateCfg->default_template;
    	$ui = edit($args,$tproject_mgr);
    	break;

    case 'doCreate':
    	$op = doCreate($args,$tproject_mgr);
    	$template= $op->status_ok ?  null : $templateCfg->default_template;
    	$ui = $op->ui;
    	$status_ok = $op->status_ok;
    	$user_feedback = $op->msg;
    	$reloadType = $op->reloadType;
    	break;

    case 'doUpdate':
    	$op = doUpdate($args,$tproject_mgr,$session_tproject_id);
    	$template= $op->status_ok ?  null : $templateCfg->default_template;
    	$ui = $op->ui;

    	$status_ok = $op->status_ok;
    	$user_feedback = $op->msg;
    	$reloadType = $op->reloadType;
        break;

    case 'doDelete':
        $op = doDelete($args,$tproject_mgr,$session_tproject_id);
    	$status_ok = $op->status_ok;
    	$user_feedback = $op->msg;
    	$reloadType = $op->reloadType;
        break;
}

$ui->main_descr = lang_get('title_testproject_management');
$smarty = new TLSmarty();
$smarty->assign('gui_cfg',$gui_cfg);
$smarty->assign('editorType',$editorCfg['type']);
$smarty->assign('mgt_view_events',$_SESSION['currentUser']->hasRight($db,"mgt_view_events"));

$feedback_type = '';	
if(!$status_ok)
{
   $feedback_type = 'error';	
   $args->doAction = "ErrorOnAction";
}

switch($args->doAction)
{
    case "doCreate":
    case "doDelete":
    case "doUpdate":
        $gui->tprojects = $tproject_mgr->get_accessible_for_user($args->userID,'array_of_map',
                                                                 " ORDER BY nodes_hierarchy.name ");

		$gui->doAction = $reloadType;
        $template= is_null($template) ? 'projectView.tpl' : $template;
        $smarty->assign('gui',$gui);
        $smarty->display($templateCfg->template_dir . $template);
    break;


    case "ErrorOnAction":
    default:
        if( $args->doAction != "edit" && $args->doAction != "ErrorOnAction")
        {
    		$of->Value = getItemTemplateContents('project_template', $of->InstanceName, $args->notes);
        }
        else
        {
        	$of->Value = $args->notes;
        }
        
        foreach($ui as $prop => $value)
        {
            $smarty->assign($prop,$value);
        }
        $smarty->assign('gui', $args);
        $smarty->assign('notes', $of->CreateHTML());
        $smarty->assign('user_feedback', $user_feedback);
        $smarty->assign('feedback_type', $feedback_type);
        $smarty->display($templateCfg->template_dir . $template);
    break;

}

/**
 * INITialize page ARGuments, using the $_REQUEST and $_SESSION
 * super-global hashes.
 * Important: changes in HTML input elements on the Smarty template
 *            must be reflected here.
 *
 * @param array $request_hash the $_REQUEST
 * @param hash session_hash the $_SESSION
 * @return singleton object with html values tranformed and other
 *                   generated variables.
 * @internal
 */
function init_args($tprojectMgr,$request_hash, $session_tproject_id)
{
    $args = new stdClass();
	$request_hash = strings_stripSlashes($request_hash);
	
	$nullable_keys = array('tprojectName','color','notes','doAction','tcasePrefix');
	foreach ($nullable_keys as $value)
	{
		$args->$value = isset($request_hash[$value]) ? trim($request_hash[$value]) : null;
	}

	$intval_keys = array('tprojectID' => 0, 'copy_from_tproject_id' => 0);
	foreach ($intval_keys as $key => $value)
	{
		$args->$key = isset($request_hash[$key]) ? intval($request_hash[$key]) : $value;
	}

	// get input from the project edit/create page
	$checkbox_keys = array('is_public' => 0,'active' => 0,'optReq' => 0,
						   'optPriority' => 0,'optAutomation' => 0,
						   'optInventory' => 0, 'issue_tracker_enabled' => 0);
	foreach ($checkbox_keys as $key => $value)
	{
		$args->$key = isset($request_hash[$key]) ? 1 : $value;
	}

	$args->issue_tracker_id = isset($request_hash['issue_tracker_id']) ? intval($request_hash['issue_tracker_id']) : 0;

	if($args->doAction != 'doUpdate' && $args->doAction != 'doCreate')
	{
		if ($args->tprojectID > 0)
		{
			$the_data = $tprojectMgr->get_by_id($args->tprojectID);
			$args->notes = $the_data['notes'];

			$args->issue_tracker_enabled = intval($the_data['issue_tracker_enabled']);	
			$args->issue_tracker_id = 0;
		   	$itMgr = new tlIssueTracker($tprojectMgr->db);
			$issueT = $itMgr->getLinkedTo($args->tprojectID);
			if( !is_null($issueT)  )
			{
				$args->issue_tracker_id = $issueT['issuetracker_id'];
			}

			if ($args->doAction == 'doDelete')
			{
				$args->tprojectName = $the_data['name'];
			}

		}
		else
		{
			$args->notes = '';
		}
	}
	
	
	$args->user = isset($_SESSION['currentUser']) ? $_SESSION['currentUser'] : null;
	$args->userID = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
	$args->testprojects = null;
	$args->projectOptions = prepareOptions($args);
	return $args;
}

/**
 * Collect a test project options (input from form) to a singleton
 * 
 * @param array $argsObj the page input
 * @return singleton data to be stored
 */
function prepareOptions($argsObj)
{
	  	$options = new stdClass();
	  	$options->requirementsEnabled = $argsObj->optReq;
	  	$options->testPriorityEnabled = $argsObj->optPriority;
	  	$options->automationEnabled = $argsObj->optAutomation;
	  	$options->inventoryEnabled = $argsObj->optInventory;

	  	return $options;
}

/**
 * 
 * 
 */
function doCreate($argsObj,&$tprojectMgr)
{
	$key2get=array('status_ok','msg');
	
	$op = new stdClass();
	$op->ui = new stdClass();
	
	$op->status_ok = 0;
	$op->template = null;
	$op->msg = '';
	$op->id = 0;
    $op->reloadType = 'none';
	
	$check_op = crossChecks($argsObj,$tprojectMgr);
	foreach($key2get as $key)
	{
	    $op->$key=$check_op[$key];
	}

	if($op->status_ok)
	{
	  	$options = prepareOptions($argsObj);
	  	    
		$new_id = $tprojectMgr->create($argsObj->tprojectName, $argsObj->color,$options, $argsObj->notes, 
									   $argsObj->active, $argsObj->tcasePrefix,
									   $argsObj->is_public);
									                 
		if (!$new_id)
		{
			$op->msg = lang_get('refer_to_log');
		}
		else
		{
			$op->template = 'projectView.tpl';
			$op->id = $new_id;
			
			if($argsObj->issue_tracker_enabled)
			{
				$tprojectMgr->enableIssueTracker($new_id);
			}
			else
			{
				$tprojectMgr->disableIssueTracker($new_id);
			}
			
			
			$itMgr = new tlIssueTracker($tprojectMgr->db);
			if($argsObj->issue_tracker_id > 0)
			{ 
				$itMgr->link($argsObj->issue_tracker_id,$new_id);
			}
			
		}
	}

	if( $op->status_ok )
	{
	    logAuditEvent(TLS("audit_testproject_created",$argsObj->tprojectName),"CREATE",$op->id,"testprojects");
    	$op->reloadType = 'reloadNavBar'; // BUGID 3048
    	
		if($argsObj->copy_from_tproject_id > 0)
		{
			$options = array('copy_requirements' => $argsObj->optReq);
			$tprojectMgr->copy_as($argsObj->copy_from_tproject_id,$new_id,
			                      $argsObj->userID,trim($argsObj->tprojectName),$options);
		}
	}
	else
	{
		$op->ui->doActionValue = 'doCreate';
		$op->ui->buttonValue = lang_get('btn_create');
		$op->ui->caption = lang_get('caption_new_tproject');
	}

    return $op;
}

/*
  function: doUpdate

  args:

  returns:

*/
function doUpdate($argsObj,&$tprojectMgr,$sessionTprojectID)
{
	
    $key2get = array('status_ok','msg');

    $op = new stdClass();
    $op->ui = new stdClass();

    $op->status_ok = 0;
    $op->msg = '';
    $op->template = null;
    $op->reloadType = 'none';

    $oldObjData = $tprojectMgr->get_by_id($argsObj->tprojectID);
    $op->oldName = $oldObjData['name'];

    $check_op = crossChecks($argsObj,$tprojectMgr);
    foreach($key2get as $key)
    {
        $op->$key=$check_op[$key];
    }

	 if($op->status_ok)
	 {
	  	$options = prepareOptions($argsObj);
        if( $tprojectMgr->update($argsObj->tprojectID,trim($argsObj->tprojectName),
        						 $argsObj->color, $argsObj->notes, $options, $argsObj->active,
        						 $argsObj->tcasePrefix, $argsObj->is_public) )
        {
        	$op->msg = '';
        	$tprojectMgr->activate($argsObj->tprojectID,$argsObj->active);
        	$tprojectMgr->setIssueTrackerEnabled($argsObj->tprojectID,$argsObj->issue_tracker_enabled);
		   	$itMgr = new tlIssueTracker($tprojectMgr->db);
		   	
		   	new dBug($argsObj);
		   	
			if( ($doLink = $argsObj->issue_tracker_id > 0)  )
			{
				$itMgr->link($argsObj->issue_tracker_id,$argsObj->tprojectID);
			}
        	else
        	{
				$issueT = $itMgr->getLinkedTo($argsObj->tprojectID);
				if( !is_null($issueT) )
				{
					$itMgr->unlink($issueT['issuetracker_id'],$issueT['testproject_id']);
				}	
        	} 
        	
        	logAuditEvent(TLS("audit_testproject_saved",$argsObj->tprojectName),"UPDATE",$argsObj->tprojectID,"testprojects");
        }
        else
        {
        	$op->status_ok=0;
        }	
	}
    if($op->status_ok)
	{
		if($sessionTprojectID == $argsObj->tprojectID)
		{
			$op->reloadType = 'reloadNavBar';
		}	
	}
	else
	{
    	$op->ui->doActionValue = 'doUpdate';
    	$op->ui->buttonValue = lang_get('btn_save');
    	$op->ui->caption = sprintf(lang_get('caption_edit_tproject'),$op->oldName);
	}

	return $op;
}


/*
  function: edit
            initialize variables to launch user interface (smarty template)
            to get information to accomplish edit task.

  args:

  returns: -

*/
function edit(&$argsObj,&$tprojectMgr)
{
	$tprojectInfo = $tprojectMgr->get_by_id($argsObj->tprojectID);
   

	$argsObj->tprojectName = $tprojectInfo['name'];
	$argsObj->projectOptions = $tprojectInfo['opt'];
	$argsObj->tcasePrefix = $tprojectInfo['prefix'];

	$k2l = array('color','notes', 'active','is_public','issue_tracker_enabled');	
	foreach($k2l as $key)
	{
		$argsObj->$key = $tprojectInfo[$key];
	}
	
	$ui = new stdClass();
	$ui->main_descr=lang_get('title_testproject_management');
	$ui->doActionValue = 'doUpdate';
	$ui->buttonValue = lang_get('btn_save');
	$ui->caption = sprintf(lang_get('caption_edit_tproject'),$argsObj->tprojectName);
	return $ui;
}

/*
  function: crossChecks
            do checks that are common to create and update operations
            - name is valid ?
            - name already exists ?
            - prefix already exits ?
  args:

  returns: -


*/
function crossChecks($argsObj,&$tprojectMgr)
{
    $op = new stdClass();
    $updateAdditionalSQLFilter = null ;
    $op = $tprojectMgr->checkName($argsObj->tprojectName);

    $check_op = array();
    $check_op['msg'] = array();
    $check_op['status_ok'] = $op['status_ok'];

    if($argsObj->doAction == 'doUpdate')
    {
        $updateAdditionalSQLFilter = " testprojects.id <> {$argsObj->tprojectID}";
    }
    
    if($check_op['status_ok'])
    {
		    if($tprojectMgr->get_by_name($argsObj->tprojectName,$updateAdditionalSQLFilter))
		    {
		    	$check_op['msg'][] = sprintf(lang_get('error_product_name_duplicate'),$argsObj->tprojectName);
		    	$check_op['status_ok'] = 0;
		    }
            
            // Check prefix no matter what has happen with previous check
		    $rs = $tprojectMgr->get_by_prefix($argsObj->tcasePrefix,$updateAdditionalSQLFilter);
		    if(!is_null($rs))
		    {
		    	$check_op['msg'][] = sprintf(lang_get('error_tcase_prefix_exists'),$argsObj->tcasePrefix);
		    	$check_op['status_ok'] = 0;
		    }
    }
    else
    {
         $check_op['msg'][] = $op['msg'];
    }
    return $check_op;
}

/*
  function: create

  args :

  returns:

*/
function create(&$argsObj,&$tprojectMgr)
{
    $argsObj->active = 1;
    $argsObj->is_public = 1;

	$gui = new stdClass();
	$gui->doActionValue = 'doCreate';
	$gui->buttonValue = lang_get('btn_create');
	$gui->caption = lang_get('caption_new_tproject');

	$gui->testprojects = $tprojectMgr->get_all(null,array('access_key' => 'id'));
    return $gui;
}


/*
  function: doDelete

  args :

  returns:

*/
function doDelete($argsObj,&$tprojectMgr,$sessionTprojectID)
{

  	$ope_status = $tprojectMgr->delete($argsObj->tprojectID);
    $op = new stdClass();
	$op->status_ok = $ope_status['status_ok'];
	$op->reloadType = 'none';

	if ($ope_status['status_ok'])
	{
		$op->reloadType = 'reloadNavBar';
		$op->msg = sprintf(lang_get('test_project_deleted'),$argsObj->tprojectName);
		logAuditEvent(TLS("audit_testproject_deleted",$argsObj->tprojectName),"DELETE",$argsObj->tprojectID,"testprojects");
	}
	else
	{
		$op->msg = lang_get('info_product_not_deleted_check_log') . ' ' . $ope_status['msg'];
	}

    return $op;
}



/*
 *
 * @internal revisions
 * @since 1.9.4
 */
function initializeGui(&$dbHandler,$argsObj)
{

	$guiObj = $argsObj;
	$guiObj->canManage = $argsObj->user->hasRight($dbHandler,"mgt_modify_product");
	$guiObj->found = 'yes';

	$itMgr = new tlIssueTracker($dbHandler);
	$guiObj->issueTrackers = $itMgr->getAll();
	// if( $argsObj->tprojectID > 0 )
	// {
	// 	$guiObj->linkedIssueTracker = $itMgr->getLinkedTo($argsObj->tprojectID);
	// 	if( !is_null($guiObj->linkedIssueTracker) )
	// 	{
	// 		$guiObj->issue_tracker_id = $guiObj->linkedIssueTracker['issuetracker_id'];
	// 	}
	// }
	return $guiObj;
}





function checkRights(&$db,&$user)
{
	return $user->hasRight($db,'mgt_modify_product');
}
?>