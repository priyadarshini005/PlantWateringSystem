<?php
	$systemLoginId = '';
	$systemPassword = '';
	if(isset($_POST["loginId"]))
		$systemLoginId = $_POST["loginId"];
	if(isset($_POST["password"]))
		$systemPassword = $_POST["password"];
	if(isset($_SERVER["HTTP_FUNCTION"]))
	{
		$functionToExecute = $_SERVER["HTTP_FUNCTION"];
		if ($functionToExecute === "authenticateUser")
		{
			if(isset($_POST["userId"]))
				authenticateUser($systemLoginId, $systemPassword, $_POST["userId"]);
			else
				authenticateUser($systemLoginId, $systemPassword, "");
		}
		else if ($functionToExecute === "updateApplicationSetup")
		{
			updateDatabase();
			echo "Execution done";
		}
	}
	function getConnection(&$dbConnect)
	{
		$servername = "localhost";
		$username = "root";
		$password = "";
		$dbname = "plant watering system";
		try
		{
			// Create connection
			$dbConnect = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
			$dbConnect->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		// Check connection
		catch(PDOException $e)
		{
			echo "Connection failed: " . $e->getMessage();
		}
	}
	function authenticateUser($loginId, $password, $userId)
	{
		getConnection($dbConnect);
		
		$sql = $dbConnect->prepare("select UserAsAdmin, GroupAsAdmin, Admin from systemsetup");
		$sql->execute();
		$result = $sql->fetch(PDO::FETCH_ASSOC);
		$authentic = false;
		if($result)
		{
			if($result["UserAsAdmin"] && $result["Admin"] === $loginId)
			{
				$authentic = checkAuthenticity($dbConnect, $userId, $loginId, $password, "usersetup");
				if($authentic)
				{
					header('authenticate: ' . $authentic);
					$sql = $dbConnect->prepare("select Id from usersetup where loginId = :loginId");
					$sql->bindParam(":loginId", $loginId);
					$sql->execute();
					$result = $sql->fetch(PDO::FETCH_ASSOC);
					if($result)
						$dbConnect->query("update systemsetup set CurrentLoggedInUser = '" . $result["Id"] . "' where PrimaryKey = 1");
				}
				else
					echo "Invalid Credentials!";
			}
			else if($result["GroupAsAdmin"] && $result["Admin"] === $loginId)
			{
				$authentic = checkAuthenticity($dbConnect, $userId, $loginId, $password, "groups");
				if($authentic)
				{
					header('authenticate: ' . $authentic);
					$dbConnect->query("update systemsetup set CurrentLoggedInUser = '" . $userId . "' where PrimaryKey = 1");
				}
				else
					echo "Invalid Credentials!";
			}
			else
			{
				echo "System setup Incomplete or User has insufficient rights!";
			}
		}
		else
			echo "System setup doesn't exist!";
	}
	function checkAuthenticity(&$dbConnect, $userId, $loginId, $password, $tableName)
	{
		$sql = $dbConnect->prepare("select Id, Password from " . $tableName . " where LoginId = :loginId");
		$sql->bindParam(':loginId', $loginId);
		$sql->execute();
		$result = $sql->fetch(PDO::FETCH_ASSOC);
		if($result)
		{
			if($userId != "")
			{
				$sql = $dbConnect->prepare("select GroupId from user where Id = :userId");
				$sql->bindParam(":userId", $userId);
				$sql->execute();
				$usrResult = $sql->fetch(PDO::FETCH_ASSOC);
				if($usrResult)
					return ($usrResult["GroupId"] == $result["Id"] && $result["Password"] == $password);
			}
			else
				return ($result["Password"] == $password);
		}
	}
	function updateDatabase()
	{
		//header("Content-Type: application/json");
		$prettyJson = file_get_contents("php://input");
		echo "<pre>" . $prettyJson . "<pre/>"; 
		$data = json_decode(file_get_contents("php://input"));
		getConnection($dbConnect);
		$slotId = "";
		if(!$data->everytime)
			$slotId = updateSlots($data, $dbConnect);
		updateApplicationSetup($data, $dbConnect, $slotId);
		header('update: ' . true);
	}
	function updateApplicationSetup($data, &$dbConnect, $slotId)
	{
		$sql = $dbConnect->prepare("select count(*) from applicationsetup");
		$sql->execute();
		$query = "";
		if($sql->fetchColumn() == 1)
		{
			$queryHead = "update applicationsetup set ";
			$queryTail = " where PrimaryKey = 1";
			if($data->everyday && $data->everytime)
				$queryBody = "Everyday=true, Always=true";
			else if(!($data->everyday) && $data->everytime)
				$queryBody = "Everyday=false, Always=true, NoOfDays=" . $data->noOfDays;
			else if($data->everyday && !($data->everytime))
				$queryBody = "Everyday=true, Always=false, NoOfTimes=" . $data->noOfTimes;
			else if(!($data->everyday) && !($data->everytime))
				$queryBody = "Everyday=false, Always=false, NoOfDays=" . $data->noOfDays . ", NoOfTimes=" . $data->noOfTimes;
			if($slotId != "")
				$queryBody .= ", TimeSlot='" . $slotId . "'";
			$query = $queryHead . $queryBody . $queryTail;
		}
		else
		{
			if($slotId == "")
				$slotId = "''";
			$query = "insert into applicationsetup (PrimaryKey, Everyday, Always, NoOfDays, NoOfTimes, TimeSlot) ";				
			$query .= "values ('1', " . ", " . $data->everyday . ", " . $data->everytime . ", ". $data->noOfDays . ", " . $data->noOfTimes . ", " . $slotId . ")";
		}
		echo $query;
		$sql = $dbConnect->prepare($query);
		$sql->execute();
		//echo "Application setup updated";
	}

	function updateSlots($data, &$dbConnect)
	{
		// Update Slots
		{
			$slotId = "";
			$sql = $dbConnect->prepare("select Id from timeslot where NoOfSlots = :noOfTimes");
			$sql->bindParam(":noOfTimes", $data->noOfTimes);
			$sql->execute();
			if($sql->rowCount() > 0)
			{
				$result = $sql->fetch(PDO::FETCH_ASSOC);
				if($result)
				{
					$slotId = $result["Id"];
					$dbConnect->query("update timeslot set LastModifiedAt = '" . date("Y-m-j H:i:s") . "' where Id = '" . $slotId . "'");
					$sql = $dbConnect->query("select EntryNo from timeslotentry where Id = '" . $slotId . "'");
					$records = $sql->fetchAll(PDO::FETCH_ASSOC);
					$index = 0;
					foreach($records as $record)
					{
						$query = "update timeslotentry set Slot = '" . $data->slots[$index] . "' where EntryNo = " . $record["EntryNo"] . " AND Id = '" . $slotId . "'";
						$dbConnect->query($query);
						$index++;
					}
				}
			}
			else
			{
				$slotId = "";
				$sql = $dbConnect->query("select Id from timeslot order by Id desc limit 1");
				$result = $sql->fetch(PDO::FETCH_ASSOC);
				if($result)
					$slotId = getNewSlotId($result["Id"]);
				else
					$slotId = "slot001";
				$query = "insert into timeslot (Id, NoOfSlots, CreatedAt, LastModifiedAt, CreatedBy) values ";
				$query .= "('" . $slotId . "', " . $data->noOfTimes . ", '" . date("Y-m-j H:i:s") . "', '" . date("Y-m-j H:i:s") . "', '" . getCurrentUser($dbConnect) . "')";
				$dbConnect->query($query);
				foreach($data->slots as $slot)
				{
					$dbConnect->query("insert into timeslotentry (Id, Slot) values ('" . $slotId . "', '" . $slot . "')");
				}
			}
		}
		return $slotId;
	}
	function getNewSlotId($lastSlotId)
	{
		return "slot" . str_pad(intval(substr($lastSlotId, 4)) + 1, 3, 0, STR_PAD_LEFT);
	}
	function getCurrentUser($dbConnect)
	{
		$sql = $dbConnect->query("select CurrentLoggedInUser from systemsetup");
		$result = $sql->fetch(PDO::FETCH_ASSOC);
		if($result)
			return $result["CurrentLoggedInUser"];
		else
			return "''";
	}
?>
