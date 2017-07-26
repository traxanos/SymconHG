<?
class HG extends IPSModule {

  private $Families = array();
  private $ChannelDevices = array();
  private $Blacklist = null;

  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('SocketID', 0);
    $this->RegisterPropertyInteger('CategoryID', 0);
    $this->RegisterPropertyInteger('SyncStatusInterval', 0);
    $this->RegisterPropertyString('Blacklist', '');
    $this->RegisterTimer('UPDATE', 0, 'HG_SyncStatus($_IPS[\'TARGET\']);');
  }

  public function ApplyChanges() {
    $this->Blacklist = null; // Clear Cache
    $this->SetTimerInterval('UPDATE', $this->ReadPropertyInteger('SyncStatusInterval') * 3600 * 1000); // in Stunden -> ms
    parent::ApplyChanges();
  }

  public function SyncStatus() {
    $socketID = $this->GetSocketID();
    $this->SearchChannelDevices();

    $response = $this->Request('listDevices', array(false));
    if($response) {
      foreach($response as $item) {
        $item = (array)$item;
        if($item['PARENT'] == "") {
          $firmware = $item['FIRMWARE'];
          $type = $item['TYPE'];
          $address = $item['ADDRESS'];
          if(@isset($this->ChannelDevices["$address:0"])) {
            $target = $this->ChannelDevices["$address:0"];
            $typeID = $this->GetOrCreateVariableByIdent($target, "TYPE", 3, "TYPE");
            SetValueString($typeID, $type);
            $firmwareID = $this->GetOrCreateVariableByIdent($target, "FIRMWARE", 3, "FIRMWARE");
            SetValueString($firmwareID, $firmware);
          }
        }
      }
    }
  }

  private function SyncFamilies() {
    $this->Families = array();
    $response = $this->Request('listFamilies');
    if($response) {
      foreach($response as $item) {
        $item = (array)$item;
        $id = $item['ID'];
        $name = $item['NAME'];
        $sub_target = $this->GetOrCreateCategoryByName($this->GetCategoryID(), $name, $id);
        $this->Families[$id] = array('name' => $name, 'target' => $sub_target);
      }
    }
  }

  public function InBlacklist($search) {
    if(!isset($this->Blacklist))
      $this->Blacklist = array_map('trim', explode(',', $this->ReadPropertyString("Blacklist")));
    return in_array($search, $this->Blacklist);
  }

  public function SyncDevices() {
    $devices = array();
    $this->SyncFamilies();
    $this->SearchChannelDevices();

    $socketID = $this->GetSocketID();

    $response = $this->Request('listDevices');
    if($response) {
      foreach($response as $item) {
        $item = (array)$item;
        $address = $item['ADDRESS'];
        $type = $item['TYPE'];
        $family = $this->Families[$item['FAMILY']];

        // Blacklist
        if($this->InBlacklist($item['ADDRESS'])) continue; // SERIAL or SERIAL:X

        // Master or Channel?
        if ($item['PARENT'] == '') {
          // Kategorie für das Device anlegen

          // Blacklist
          if($this->InBlacklist($item['TYPE'])) continue; // eg HM-LC-Sw1PBU-FM

          $name = $this->GetNameByDeviceID($item['ID']);
          $target = $this->GetOrCreateCategoryByName($family['target'], $name, 100);
          $devices[$address] = array('name' => $name, 'target' => $target, 'type' => $type, 'counter' => 0);
        } else {
          // ChannelDevices anlegen

          // Blacklist
          if($this->InBlacklist($item['PARENT'])) continue; // SERIAL of parent
          if($this->InBlacklist($item['PARENT_TYPE'])) continue; // TYPE of parent eg HM-LC-Sw1PBU-FM
          if($this->InBlacklist($item['TYPE'])) continue; // CHANNEL NAME eg WEATHER_TRANSMIT
          if($this->InBlacklist($item['PARENT'].':'.$item['TYPE'])) continue; // SERIAL:CHANNEL NAME eg WEATHER_TRANSMIT

          $type = $item['TYPE'];
          $channel = $item['CHANNEL'];
          // Ist das eigentliche Device nicht vorhanden so wurde es geblacklistet und so können die Channels übersprungen werden
          $parent = $devices[$item['PARENT']];
          $name = $parent['name'];
          $position = $parent['counter'];
          $devices[$item['PARENT']]['counter']++;

          if (array_key_exists($address, $this->ChannelDevices)) {
            $channelNew = false;
            $channelID = $this->ChannelDevices[$address];
            unset($this->ChannelDevices[$address]);
          } else {
            $channelNew = true;
            $channelID = IPS_CreateInstance("{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}");
          }

	  if (IPS_GetInstance($channelID)['ConnectionID'] <> $socketID) {
            echo "Reassign";
            IPS_DisconnectInstance($channelID);
            IPS_ConnectInstance($channelID, $this->socket);
          }

          IPS_SetPosition($channelID, $position);
          IPS_SetParent($channelID, $parent['target']);
          if($channelNew) IPS_SetName($channelID, "$type");
          IPS_SetProperty($channelID, 'Address', $address);
          IPS_SetProperty($channelID, 'Protocol', 0);
          IPS_SetProperty($channelID, 'EmulateStatus', false);
          @IPS_ApplyChanges($channelID);
        }
      }
    }
  }

  private function SearchChannelDevices() {
    $this->ChannelDevices = array();
    $devices = IPS_GetInstanceListByModuleID("{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}");
    foreach ($devices as $id) {
      $address = IPS_GetProperty($id, "Address");
      $this->ChannelDevices[$address] = $id;
    }
  }

  private function GetOrCreateVariableByIdent($target, $ident, $type, $name, $icon = '') {
    $id = @IPS_GetObjectIDByIdent($ident, $target);
    if ($id == 0) {
      $id = IPS_CreateVariable($type);
      IPS_SetIdent($id, $ident);
      IPS_SetParent($id, $target);
      IPS_SetName($id, $name);
      IPS_SetIcon($id, $icon);
    }
    return $id;
  }

  private function GetOrCreateCategoryByName($target, $name, $position = false, $icon = '') {
    $id = @IPS_GetObjectIDByName($name, $target);
    if ($id == 0) {
      $id = IPS_CreateCategory();
      IPS_SetParent($id, $target);
      IPS_SetName($id, $name);
      IPS_SetIcon($id, $icon);
    }
    IPS_SetPosition($id, $position);
    return $id;
  }

  public function GetNameByDeviceID($id) {
    return $this->Request('getName', array($id));
  }

  private function GetSocketID() {
    $SocketID = $this->ReadPropertyInteger('SocketID');
    $Socket = IPS_GetInstance($SocketID);
    if($Socket['ModuleInfo']['ModuleID'] <> '{A151ECE9-D733-4FB9-AA15-7F7DD10C58AF}') {
      $this->SetStatus(201);
      return false;
    } else {
      return $SocketID;
    }
  }

  public function GetCategoryID() {
    return $this->ReadPropertyInteger('CategoryID');
  }

  public function Request(string $method, array $params = array()) {
    $SocketID = $this->GetSocketID();
    if($SocketID > 0) {
      $host = IPS_GetProperty($SocketID, 'IPAddress');
      $port = IPS_GetProperty($SocketID, 'RFPort');
      $url = "http://$host:$port";

      $curl = curl_init();
      curl_setopt($curl, CURLOPT_URL, $url);
      curl_setopt($curl, CURLOPT_HEADER, 0);
      curl_setopt($curl, CURLOPT_POST, 1);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curl, CURLOPT_USERAGENT,'Symcon');
      curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

      $data = array();
      $data['jsonrpc'] = '2.0';
      $data['id'] = '123';
      $data['method'] = $method;
      if(isset($params) && count($params) > 0) $data['params'] = $params;

      curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));

      $response = curl_exec($curl);
      $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

      if ($code == 200) {
        $response = json_decode($response);
        if(@isset($response->error)) {
           IPS_LogMessage("SymconHG", "Error on $method: " . print_r($response->error, 1));
        } else {
          $this->SetStatus(102);
          return $response->result;
        }
      } else {
        $this->SetStatus(201);
      }
      return false;
    }
  }

}
?>
