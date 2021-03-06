<?php
require(dirname(__FILE__).'/../common.php');

//touch( '~/thermo2/scripts/thermo_update_status.start' );

/**
	* (this note may be obsolete)
	* Because of different structure and use of hvac_status table, migration from old version to new version is non-trivial.
	* The best bet is to set up the new code running in parallel and then once it's verified working in your environment
	*  shut down the old data collectors, export your old historic temperature data and then import into the new structure.
	*  Then drop your old tables and delete your old install
	*
	* Updated pretty much directly from phareous code fork.
	*/

/**
	* This script periodically (once a minute) queries each thermostat and writes the status into
	* the hvac_status table. There is just one record in the hvac_status table for each
	* thermostat and it shows the current status of the heat, cool, and fan, plus the
	* time it saw that those first started.
	*
	* For each run the status is updated but not the start time. Once it goes from off to on, the start_time is updated.
	* When it goes from on to off, an entry is added to hvac_cycles
	* Date is simply the last time the status was updated
	*/

try
{
	// Query to location info about the thermostat.  Might find nothing if this is the first time.
	$sql = "SELECT * FROM {$dbConfig['table_prefix']}hvac_status WHERE tstat_uuid=?"; // Really should name columns instead of using *
	$getStatInfo = $pdo->prepare( $sql );

	// If this was thr first contact, add info about the stat to the DB
	$sql = "INSERT INTO {$dbConfig['table_prefix']}hvac_status( tstat_uuid, date, start_date_heat, start_date_cool, start_date_fan, heat_status, cool_status, fan_status ) VALUES( ?, ?, ?, ?, ?, ?, ?, ? )";
	$insertStatInfo = $pdo->prepare( $sql );

	// Modify the thermostat data
	$sql = "UPDATE {$dbConfig['table_prefix']}thermostats SET tstat_uuid = ?, description = ?, model = ?, fw_version = ?, wlan_fw_version = ? WHERE id = ?";
	$updateStatInfo = $pdo->prepare( $sql );

	$sql = "UPDATE {$dbConfig['table_prefix']}hvac_status SET date = ?, start_date_heat = ?, start_date_cool = ?, start_date_fan = ?, heat_status = ?, cool_status = ?, fan_status = ? WHERE tstat_uuid = ?";
	$updateStatStatus = $pdo->prepare( $sql );

	$sql = "INSERT INTO {$dbConfig['table_prefix']}hvac_cycles( tstat_uuid, system, start_time, end_time ) VALUES( ?, ?, ?, ? )";
	$cycleInsert = $pdo->prepare( $sql );

	// Query to retrieve prior setpoint.  Might find nothing if this is the first time.
	$sql = "SELECT set_point FROM {$dbConfig['table_prefix']}setpoints WHERE id=? ORDER BY switch_time DESC LIMIT 1";
	$getPriorSetPoint = $pdo->prepare( $sql );

	$sql = "INSERT INTO {$dbConfig['table_prefix']}setpoints( id, set_point, switch_time ) VALUES( ?, ?, ? )";
	$insertSetPoint = $pdo->prepare( $sql );
}
catch( Exception $e )
{
	$log->logInfo( 'status: DB Exception while preparing SQL: ' . $e->getMessage() );
	die();
}

global $lockFile;

$now = date( 'Y-m-d H:i:00' );
foreach( $thermostats as $thermostatRec )
{
	$lockFileName = $lockFile . $thermostatRec['id'];
	$lock = @fopen( $lockFileName, 'w' );
	if( !$lock )
	{
		error( "status: Could not write to lock file $lockFileName" );
		continue;
	}

	if( flock($lock, LOCK_EX) )
	{
		try
		{
			// Query thermostat info
			//$log->logInfo( "status: Connecting to Thermostat ID = ({$thermostatRec['id']})  uuid  = ({$thermostatRec['tstat_uuid']}) ip = ({$thermostatRec['ip']}) name = ({$thermostatRec['name']})" );
			$stat = new Stat( $thermostatRec['ip'] );

			//$uuid = $stat->getUUid(); // This data is gathered by the getSysInfo() function
			//$fwVersion = $stat->getFwVersion(); // This data is gathered by the getSysInfo() function
			//$wlanFwVersion = $stat->getWlanFwVersion(); // This data is gathered by the getSysInfo() function
			$stat->getSysInfo();
// A timeout has been added to the library but nothing done with it yet (other than logging).  Maybe throw an error if no connection?

			/**
				* On "new" contact the stat and get the "big download" that sets the most variables - instead of using multiple hits.
				*
				* To determine "new" or not, if the thermostat DB has ONLY the ip address and ID, then it's new.
				*/
			$stat->getModel();

			/**
				* This catches the uuid which is required for data insert.
				*
				* Or can we rely upon the value stored in the thermostats table?
				*
				* What do we do when there is a changed thermostat?  The history is tied to the uuid. That is BAD
				* Need a system generated surrogate key instead of uuid to join from thermostat table to data table.
				* Should compare the detected uuid back to the thermostat table record
				* On match, do nothing.  On 'no match', make sure it matches no other record too and then update existing record (and log it)
				*/
			$stat->getSysName();

//$log->logInfo( "status: I am declining to update the thermostat info for now," );
			//$log->logInfo( "status: Updating thermostat record {$thermostatRec['id']}: UUID $stat->uuid DESC $stat->sysName MDL $stat->model FW $stat->fw_version WLANFW $stat->wlan_fw_version" );
			//Update thermostat info in DB
			//$updateStatInfo->execute(array( $stat->uuid , $stat->sysName, $stat->model, $stat->fw_version, $stat->wlan_fw_version, $thermostatRec['id']));

			// Get thermostat state
			$statData = $stat->getStat();
			$heatStatus = ($stat->tstate == 1) ? true : false;
			$coolStatus = ($stat->tstate == 2) ? true : false;
			$fanStatus  = ($stat->fstate == 1) ? true : false;
			//$log->logInfo( 'status: Heat: ' . ($heatStatus ? 'ON' : 'OFF') );
			//$log->logInfo( 'status: Cool: ' . ($coolStatus ? 'ON' : 'OFF') );
			//$log->logInfo( 'status: Fan: ' . ($fanStatus ? 'ON' : 'OFF') );

			// Get current setPoint from thermost
			// t_heat or t_cool may not exist if thermostat is running in battery mode
			$setPoint = ($stat->tmode == 1) ? $stat->t_heat : $stat->t_cool;

			// Get prior setPoint from database
			$getPriorSetPoint->execute(array($thermostatRec['id']));
			$priorSetPoint = $getPriorSetPoint->fetchColumn();

			// Get prior state info from DB
			$priorStartDateHeat = null;
			$priorStartDateCool = null;
			$priorStartDateFan = null;
			$priorHeatStatus = false;
			$priorCoolStatus = false;
			$priorFanStatus = false;

			$getStatInfo->execute( array( $stat->uuid ) );
			if( $getStatInfo->rowCount() < 1 )
			{ // not found - this is the first time connection for this thermostat
				$log->logInfo( 'status: New thermostat (or communication error with existing)' );
// Perhaps key in on this logic to drive the deep query for the stat??
				$startDateHeat = ($heatStatus) ? $now : null;
				$startDateCool = ($coolStatus) ? $now : null;
				$startDateFan = ($fanStatus) ? $now : null;
				$log->logInfo( "status: Inserting record for a brand new never before seen thermostat with time = ($now) H $heatStatus C $coolStatus F $fanStatus SDH $startDateHeat SDC $startDateCool SDF $startDateFan for UUID $stat->uuid" );
				$insertStatInfo->execute( array( $stat->uuid, $now, $startDateHeat, $startDateCool, $startDateFan, $heatStatus, $coolStatus, $fanStatus ) );

				$log->logInfo( "setpoints: Inserting record for a brand new never before seen thermostat with setpoint=$setPoint, time=($now) " );
				$insertSetPoint->execute( array( $thermostatRec['id'], $setPoint, $now ) );
// Have been getting really lucky here.  Communicatiuon errors with the stat leave NULLs in that do not match existing stats.
// Leading to false idea that the stat we're talking to is new (because existing stats not equal NULL on uuid)
// So attempt to insert record for new stat, but fail because no NULLs allowed in key columns.  So no new record.  Lucky!
// Proper fix is to abort when the stat was not able to be reached.
// Also proper fix includes not trying to insert NULLs and catching SQL errors when something happens like that (and log it)
			}
			else
			{
				while( $row = $getStatInfo->fetch( PDO::FETCH_ASSOC ) )
				{ // This SQL had better pull only one row or else there is a data integrity problem!
					// and without an ORDER BY on the SELECT there is no way to know you're geting the same row from this each time
					$priorStartDateHeat = $row['start_date_heat'];
					$priorStartDateCool = $row['start_date_cool'];
					$priorStartDateFan = $row['start_date_fan'];
					$priorHeatStatus = (bool)$row['heat_status'];
					$priorCoolStatus = (bool)$row['cool_status'];
					$priorFanStatus = (bool)$row['fan_status'];
				}
				//$log->logInfo( "status:  uuid = ($stat->uuid) GOT PRIOR STATE H $priorHeatStatus C $priorCoolStatus F $priorFanStatus SDH $priorStartDateHeat SDC $priorStartDateCool SDC $priorStartDateFan" );

				// update start dates if the cycle just started
				$newStartDateHeat = (!$priorHeatStatus && $heatStatus) ? $now : $priorStartDateHeat;
				$newStartDateCool = (!$priorCoolStatus && $coolStatus) ? $now : $priorStartDateCool;
				$newStartDateFan = (!$priorFanStatus && $fanStatus) ? $now : $priorStartDateFan;

				// if status has changed from on to off, update hvac_cycles
				if( $priorHeatStatus && !$heatStatus )
				{
					//$log->logInfo( "status: uuid = ($stat->uuid) Finished Heat Cycle - Adding Hvac Cycle Record for $stat->uuid 1 $priorStartDateHeat $now" );
					$cycleInsert->execute( array( $stat->uuid, 1, $priorStartDateHeat, $now ) );
					$newStartDateHeat = null;
				}
				if( $priorCoolStatus && !$coolStatus )
				{
					//$log->logInfo( "status: $stat->uuid Finished Cool Cycle - Adding Hvac Cycle Record for $stat->uuid 2 $priorStartDateCool $now" );
					$cycleInsert->execute( array( $stat->uuid, 2, $priorStartDateCool, $now ) );
					$newStartDateCool = null;
				}
				if( $priorFanStatus && !$fanStatus )
				{
					//$log->logInfo( "status: $stat->uuid Finished Fan Cycle - Adding Hvac Cycle Record for $stat->uuid 3 $priorStartDateFan $now" );
					$cycleInsert->execute( array( $stat->uuid, 3, $priorStartDateFan, $now ) );
					$newStartDateFan = null;
				}
				// update the status table
				//$log->logInfo( "status: Updating record with $now SDH $newStartDateHeat SDC $newStartDateCool SDF $newStartDateFan H $heatStatus C $coolStatus F $fanStatus for UUID $stat->uuid" );
				$updateStatStatus->execute( array( $now, $newStartDateHeat, $newStartDateCool, $newStartDateFan, $heatStatus, $coolStatus, $fanStatus, $stat->uuid ) );

				//Update the setpoints table
				if( $setPoint != $priorSetPoint )
				{
					$log->logInfo( "status: Inserting changed setpoint record SP=$setPoint, time=($now) " );
					$insertSetPoint->execute( array( $thermostatRec['id'], $setPoint, $now ) );
				}
			}
		}
		catch( Exception $e )
		{
			$log->logInfo( 'status: Thermostat Exception ' . $e->getMessage() );
			//flock( $lock, LOCK_UN );	// Should be in a finally block?
			//die();										// Does die() prevent finally?
		}
		flock( $lock, LOCK_UN );
	}
	else
	{
		$log->logInfo( "status: Couldn't get file lock for thermostat {$thermostatRec['id']}" );
		die();
	}
	fclose( $lock );
}
//touch( '~/thermo2/scripts/thermo_update_status.end' );
?>