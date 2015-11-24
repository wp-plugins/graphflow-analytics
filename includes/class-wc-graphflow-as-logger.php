<?php

/**
 * Class WC_Graphflow_ActionScheduler_Logger
 *
 * Prevents Action Scheduler logging to comments
 */
if (! class_exists('ActionScheduler_wpCommentLogger') ) {
	require_once 'action-scheduler/classes/ActionScheduler_Logger.php';
	require_once 'action-scheduler/classes/ActionScheduler_wpCommentLogger.php';
}

class WC_Graphflow_ActionScheduler_Logger extends ActionScheduler_wpCommentLogger {

	public function log( $action_id, $message, DateTime $date = null ) {
		$groups = wp_get_post_terms( $action_id, 'action-group' );
		$is_gf = false;

		foreach ( $groups as $group ) {
			if ( $group->slug == 'graphflow_email' ) {
				$is_gf = true;
				break;
			}
		}

		if ( !$is_gf ) {
			parent::log( $action_id, $message, $date );
		}


	}

}