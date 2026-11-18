<?php

declare(strict_types=1);

/**
 * Z2M Bridge Kachel (HTML-SDK)
 *
 * Bedienkachel für eine Instanz „Zigbee2MQTT Bridge“ (Modul Schnittcher/IPS-Zigbee2MQTT):
 * Status, Versionen, Netzwerkkanal, Anzahl Geräte, Anlernen (permit_join) mit Countdown,
 * Protokollstufe und Neustart (mit Rückfrage).
 * Ab 0.3: neu angelernte Geräte (aus zigbee2mqtt/bridge/event) mit Interview-Status und Umbenennen.
 * Dafür hängt die Instanz wie die Bridge am MQTT-Server und liest dort mit.
 *
 * Geschaltet wird über die Aktionen der Bridge-Variablen (RequestAction), also genau wie in der Konsole.
 */
class Z2MBridgeKachel extends IPSModuleStrict
{
    private const BRIDGE_GUID = '{00160D82-9E2F-D1BD-6D0B-952F945332C5}';
    private const DEVICE_GUID = '{E5BB36C6-A70B-EB23-3716-9151A09AC8A2}';
    private const LOG_LEVELS = ['error', 'warning', 'info', 'debug'];
    private const NEW_MAX = 20;          // gemerkte neue Geräte
    private const NEW_SHOW_DAYS = 7;     // so lange erscheinen sie in der Kachel

    // Idents der Bridge-Variablen
    private const IDENTS = [
        'state', 'permit_join', 'permit_join_timeout', 'log_level', 'restart_request', 'restart_required',
        'version', 'zigbee_herdsman', 'zigbee_herdsman_converters', 'network_channel',
        'extension_loaded', 'extension_version', 'extension_is_current'
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('BridgeID', 0);
        $this->RegisterPropertyInteger('PermitJoinTime', 254);   // Zeit, die die Bridge beim Anlernen sendet (SetPermitJoin: 254 s)
        $this->RegisterPropertyString('CoordinatorHost', '');    // optional: SMLIGHT-Koordinator (SLZB-06 u. a.), IP oder Hostname
        $this->RegisterPropertyInteger('CoordinatorInterval', 60); // Abfrage in s

        $this->RegisterAttributeString('Watched', '[]');
        $this->RegisterAttributeInteger('RestartAt', 0);
        $this->RegisterAttributeString('Coordinator', '{}');
        $this->RegisterAttributeInteger('CoordRestartAt', 0);
        $this->RegisterAttributeString('NewDevices', '[]');

        $this->RegisterTimer('CoordinatorUpdate', 0, 'Z2MK_UpdateCoordinator($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->WatchDesign();

        foreach ((array) json_decode($this->ReadAttributeString('Watched'), true) as $w) {
            $this->UnregisterMessage((int) $w[0], (int) $w[1]);
        }
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }

        if ($this->CoordHost() === '') {
            $this->SetTimerInterval('CoordinatorUpdate', 0);
            $this->WriteAttributeString('Coordinator', '{}');
        } else {
            $this->SetTimerInterval('CoordinatorUpdate', max(10, $this->ReadPropertyInteger('CoordinatorInterval')) * 1000);
            if (IPS_GetKernelRunlevel() === KR_READY) {
                $this->UpdateCoordinator();
            }
        }

        $watch = [];
        $bid = $this->ReadPropertyInteger('BridgeID');
        if (!$this->BridgeOk($bid)) {
            $this->WriteAttributeString('Watched', '[]');
            $this->SetStatus(201);
            $this->Push();
            return;
        }
        $this->RegisterReference($bid);
        // nur Ereignisse und Antworten zum Umbenennen der Bridge empfangen
        // .{1,2} statt / : passt auf / und auf \/ (falls der Schrägstrich im JSON maskiert ist)
        $base = preg_quote($this->BaseTopic($bid));
        $this->SetReceiveDataFilter('.*"Topic":"' . $base . '.{1,2}bridge.{1,2}(event|response.{1,2}device.{1,2}rename)".*');
        foreach ($this->Vars() as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
            $watch[] = [$vid, VM_UPDATE];
        }
        $this->WriteAttributeString('Watched', json_encode($watch));
        $this->SetStatus(IS_ACTIVE);
        $this->Push();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        $this->Push();
    }

    /**
     * Farben und Layout aus dem Modul „Visu Design“ (W5D, ab 1.5); das module.html hat kein eigenes Layout-CSS.
     * Das CSS steht im Element #w5d und kommt zusätzlich mit jedem Zustand mit (Feld design), damit ein
     * Theme-Wechsel auch in zwischengespeicherten Kacheln ohne Neuladen ankommt.
     */
    private function DesignStyle(): string
    {
        return '<style id="w5d">' . $this->DesignCSS() . '</style>';
    }

    private function DesignID(): int
    {
        $guid = '{018EEC85-8FFC-4E1F-A966-702C4BBDC2E8}';
        if (!IPS_ModuleExists($guid)) {
            return 0;
        }
        $ids = IPS_GetInstanceListByModuleID($guid);
        sort($ids);
        return $ids[0] ?? 0;
    }

    /** Farben und Kachel-Bausteine aus Visu Design (ab 1.5); die Kachel bringt kein eigenes Layout-CSS mit */
    private function DesignCSS(): string
    {
        $id = $this->DesignID();
        if ($id <= 0 || !function_exists('W5D_GetCSS')) {
            return '';
        }
        return W5D_GetCSS($id) . (function_exists('W5D_GetTileCSS') ? W5D_GetTileCSS($id) : '');
    }

    /** Hell/Dunkel-Erkennung (w5tTheme) aus Visu Design */
    private function DesignScript(): string
    {
        $id = $this->DesignID();
        return $id > 0 && function_exists('W5D_GetTileJS') ? '<script>' . W5D_GetTileJS($id) . '</script>' : '';
    }

    /** Theme-Wechsel in „Visu Design“ melden lassen (IM_CHANGESETTINGS → Zustand mit neuem Design-CSS senden). */
    private function WatchDesign(): void
    {
        $id = $this->DesignID();
        if ($id > 0) {
            $this->RegisterMessage($id, IM_CHANGESETTINGS);
        }
    }

    public function GetVisualizationTile(): string
    {
        $initial = '<script>handleMessage(' . json_encode(json_encode($this->BuildState())) . ');</script>';
        return $this->DesignStyle() . file_get_contents(__DIR__ . '/module.html') . $this->DesignScript() . $initial;
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $vars = $this->Vars();
        switch ($Ident) {
            case 'PermitJoin':
                if (isset($vars['permit_join'])) {
                    RequestAction($vars['permit_join'], (bool) $Value);
                }
                break;
            case 'LogLevel':
                if (isset($vars['log_level']) && in_array((string) $Value, self::LOG_LEVELS, true)) {
                    RequestAction($vars['log_level'], (string) $Value);
                }
                break;
            case 'Restart':
                if (isset($vars['restart_request'])) {
                    $this->WriteAttributeInteger('RestartAt', time());
                    $this->LogMessage('Neustart von Zigbee2MQTT über die Kachel angefordert', KL_NOTIFY);
                    RequestAction($vars['restart_request'], 0);
                }
                break;
            case 'RestartCoordinator':
                $this->RestartCoordinator();
                break;
            case 'RenameDevice':
                $p = json_decode((string) $Value, true);
                if (is_array($p)) {
                    $this->RenameDevice((string) ($p['ieee'] ?? ''), trim((string) ($p['to'] ?? '')));
                }
                break;
            case 'DismissDevice':
                $this->EditNew(function (array &$list) use ($Value) {
                    foreach ($list as &$d) {
                        if ($d['ieee'] === (string) $Value) {
                            $d['hidden'] = true;
                        }
                    }
                });
                break;
            case 'Refresh':
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->Push();
    }

    /* ---------- Neue Geräte (zigbee2mqtt/bridge/event) ---------- */

    public function ReceiveData(string $JSONString): string
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d) || !isset($d['Topic'])) {
            return '';
        }
        $payload = $this->DecodePayload((string) ($d['Payload'] ?? ''));
        if (!is_array($payload)) {
            return '';
        }
        $topic = substr((string) $d['Topic'], strlen($this->BaseTopic($this->ReadPropertyInteger('BridgeID'))) + 1);
        if ($topic === 'bridge/event') {
            $this->HandleEvent((string) ($payload['type'] ?? ''), (array) ($payload['data'] ?? []));
        } elseif ($topic === 'bridge/response/device/rename') {
            $this->HandleRenameResponse($payload);
        }
        return '';
    }

    private function HandleEvent(string $type, array $data): void
    {
        $ieee = (string) ($data['ieee_address'] ?? '');
        if ($ieee === '' || !in_array($type, ['device_joined', 'device_interview', 'device_leave'], true)) {
            return;   // device_announce kommt auch von bekannten Geräten und wird nicht gezeigt
        }
        $name = (string) ($data['friendly_name'] ?? $ieee);
        $this->SendDebug('Ereignis', $type . ' ' . $name . ' ' . json_encode($data), 0);
        $this->EditNew(function (array &$list) use ($type, $data, $ieee, $name) {
            $i = $this->FindNew($list, $ieee);
            if ($i === null) {
                if ($type === 'device_leave') {
                    return;   // unbekanntes Gerät verlässt das Netz: nicht anzeigen
                }
                array_unshift($list, ['ieee' => $ieee, 'name' => $name, 'joined' => time(), 'status' => 'joined', 'hidden' => false]);
                $i = 0;
            }
            $e = &$list[$i];
            $e['name'] = $name;
            $e['updated'] = time();
            if ($type === 'device_joined') {
                $e['status'] = 'joined';
                $e['hidden'] = false;
            } elseif ($type === 'device_interview') {
                $st = (string) ($data['status'] ?? '');
                $e['status'] = ['started' => 'interview', 'successful' => 'ok', 'failed' => 'failed'][$st] ?? $e['status'];
                if ($st === 'successful') {
                    $def = (array) ($data['definition'] ?? []);
                    $e['supported'] = (bool) ($data['supported'] ?? true);
                    $e['vendor'] = (string) ($def['vendor'] ?? '');
                    $e['model'] = (string) ($def['model'] ?? '');
                    $e['desc'] = (string) ($def['description'] ?? '');
                }
                $e['hidden'] = false;
            } elseif ($type === 'device_leave') {
                $e['status'] = 'left';
            }
        });
        if ($type === 'device_interview' && ($data['status'] ?? '') === 'successful') {
            $this->LogMessage('Zigbee: neues Gerät angelernt: ' . $name . ' (' . $ieee . ')', KL_NOTIFY);
        }
    }

    private function RenameDevice(string $ieee, string $to): void
    {
        $bid = $this->ReadPropertyInteger('BridgeID');
        $list = $this->ReadNew();
        $i = $this->FindNew($list, $ieee);
        if ($i === null || !$this->BridgeOk($bid)) {
            return;
        }
        $from = $list[$i]['name'];
        $err = $this->CheckName($to, $from);
        if ($err === '') {
            $this->EditNew(function (array &$l) use ($ieee, $to) {
                $k = $this->FindNew($l, $ieee);
                if ($k !== null) {
                    $l[$k]['renameTo'] = $to;
                    $l[$k]['renameAt'] = time();
                    $l[$k]['renameError'] = '';
                }
            });
            $this->Push();
            $this->SendDebug('Umbenennen', $from . ' -> ' . $to, 0);
            // Z2M_RenameDevice wartet bis zu 5 s auf die Antwort; das Ergebnis (ok/error) kommt über bridge/response/device/rename
            if (!@Z2M_RenameDevice($bid, $from, $to)) {
                $err = 'Zigbee2MQTT antwortet nicht';
            }
        }
        if ($err !== '') {
            $this->EditNew(function (array &$l) use ($ieee, $err, $to) {
                $k = $this->FindNew($l, $ieee);
                // nur, wenn die Antwort von Z2M nicht schon verarbeitet ist
                if ($k !== null && ($l[$k]['name'] !== $to || ($l[$k]['renameTo'] ?? '') !== '')) {
                    $l[$k]['renameTo'] = '';
                    $l[$k]['renameError'] = $err;
                }
            });
        }
    }

    private function HandleRenameResponse(array $p): void
    {
        $from = (string) ($p['data']['from'] ?? '');
        $to = (string) ($p['data']['to'] ?? '');
        $ok = ($p['status'] ?? '') === 'ok';
        $this->SendDebug('Antwort Umbenennen', json_encode($p), 0);
        $hit = false;
        $this->EditNew(function (array &$l) use ($from, $to, $ok, $p, &$hit) {
            foreach ($l as &$e) {
                if (($e['renameTo'] ?? '') !== '' && ($e['name'] === $from || $e['ieee'] === $from)) {
                    $hit = true;
                    if ($ok) {
                        $e['name'] = $to !== '' ? $to : $e['renameTo'];
                        $e['renameError'] = '';
                    } else {
                        $e['renameError'] = (string) ($p['error'] ?? 'Umbenennen abgelehnt');
                    }
                    $e['renameTo'] = '';
                }
            }
        });
        if ($ok && $from !== '' && $to !== '') {
            $this->FollowRename($from, $to);
            if (!$hit) {
                $this->Push();
            }
        }
    }

    /** Symcon-Geräteinstanzen mit dem alten Topic auf den neuen Namen umstellen (sonst verlieren sie die Verbindung). */
    private function FollowRename(string $from, string $to): void
    {
        foreach ($this->DeviceInstances() as $id) {
            if (IPS_GetProperty($id, 'MQTTTopic') !== $from) {
                continue;
            }
            IPS_SetProperty($id, 'MQTTTopic', $to);
            if (IPS_GetName($id) === $from) {
                IPS_SetName($id, $to);
            }
            IPS_ApplyChanges($id);
            $this->LogMessage('Zigbee: Instanz ' . $id . ' folgt der Umbenennung ' . $from . ' -> ' . $to, KL_NOTIFY);
        }
    }

    /** Leer = Name zulässig, sonst Fehlertext */
    private function CheckName(string $to, string $from): string
    {
        if ($to === '') {
            return 'Name fehlt';
        }
        if ($to === $from) {
            return 'Name ist unverändert';
        }
        if (strlen($to) > 100 || preg_match('/[+#]|^\/|\/$|\/\/|\/\d+$|\/(set|get)$/', $to)) {
            return 'Name nicht zulässig (keine + # und kein / am Anfang oder Ende)';
        }
        foreach ($this->DeviceInstances() as $id) {
            if (IPS_GetProperty($id, 'MQTTTopic') === $to) {
                return 'Name ist schon vergeben (Instanz ' . $id . ')';
            }
        }
        return '';
    }

    /** Zigbee2MQTT-Geräteinstanzen am selben MQTT-Server wie die Bridge */
    private function DeviceInstances(): array
    {
        $bid = $this->ReadPropertyInteger('BridgeID');
        if (!$this->BridgeOk($bid)) {
            return [];
        }
        $parent = IPS_GetInstance($bid)['ConnectionID'];
        return array_values(array_filter(IPS_GetInstanceListByModuleID(self::DEVICE_GUID), fn ($id) => IPS_GetInstance($id)['ConnectionID'] === $parent));
    }

    private function BaseTopic(int $bid): string
    {
        $t = $this->BridgeOk($bid) ? (string) @IPS_GetProperty($bid, 'MQTTBaseTopic') : '';
        return $t !== '' ? $t : 'zigbee2mqtt';
    }

    private function DecodePayload(string $p): ?array
    {
        $r = json_decode($p, true);
        if (!is_array($r) && $p !== '' && ctype_xdigit($p) && strlen($p) % 2 === 0) {
            $r = json_decode((string) hex2bin($p), true);   // Payload hexkodiert
        }
        if (!is_array($r) && function_exists('mb_convert_encoding')) {
            $r = json_decode(mb_convert_encoding($p, 'UTF-8', 'ISO-8859-1'), true);   // Payload als Latin-1
        }
        return is_array($r) ? $r : null;
    }

    private function ReadNew(): array
    {
        return json_decode($this->ReadAttributeString('NewDevices'), true) ?: [];
    }

    private function FindNew(array $list, string $ieee): ?int
    {
        foreach ($list as $i => $e) {
            if (($e['ieee'] ?? '') === $ieee) {
                return $i;
            }
        }
        return null;
    }

    /** Liste unter Sperre ändern (Empfang und Bedienung laufen in verschiedenen Threads) und an die Kachel senden */
    private function EditNew(callable $fn): void
    {
        $sem = 'Z2MK_New_' . $this->InstanceID;
        IPS_SemaphoreEnter($sem, 3000);
        try {
            $list = $this->ReadNew();
            $fn($list);
            $this->WriteAttributeString('NewDevices', json_encode(array_slice(array_values($list), 0, self::NEW_MAX)));
        } finally {
            IPS_SemaphoreLeave($sem);
        }
        $this->Push();
    }

    /** sichtbare neue Geräte für die Kachel, mit Symcon-Instanz (falls schon angelegt) */
    private function NewForTile(): array
    {
        $topics = [];
        foreach ($this->DeviceInstances() as $id) {
            $topics[IPS_GetProperty($id, 'MQTTTopic')] = $id;
        }
        $r = [];
        foreach ($this->ReadNew() as $e) {
            if (!empty($e['hidden']) || time() - (int) $e['joined'] > self::NEW_SHOW_DAYS * 86400) {
                continue;
            }
            $e['instance'] = $topics[$e['name']] ?? 0;
            unset($e['hidden']);
            $r[] = $e;
        }
        return $r;
    }

    /* ---------- Koordinator (SMLIGHT, z. B. SLZB-06) ---------- */

    /** Fragt /ha_info, /ha_sensors und /metrics des Koordinators ab (Timer). */
    public function UpdateCoordinator(): bool
    {
        $host = $this->CoordHost();
        if ($host === '') {
            return false;
        }
        $old = json_decode($this->ReadAttributeString('Coordinator'), true) ?: [];
        $info = json_decode((string) $this->HttpGet($host, '/ha_info'), true);
        $sens = is_array($info) ? json_decode((string) $this->HttpGet($host, '/ha_sensors'), true) : null;
        $c = ['ts' => time(), 'ok' => is_array($info) && isset($info['Info']), 'lastOk' => (int) ($old['lastOk'] ?? 0)];
        if ($c['ok']) {
            $i = $info['Info'];
            $s = is_array($sens) ? ($sens['Sensors'] ?? []) : [];
            $c['lastOk'] = $c['ts'];
            $c += [
                'model'      => (string) ($i['model'] ?? ''),
                'hostname'   => (string) ($i['hostname'] ?? ''),
                'fw'         => (string) ($i['sw_version'] ?? ''),
                'zbHw'       => (string) ($i['zb_hw'] ?? ''),
                'zbFw'       => (string) ($i['zb_version'] ?? ''),
                'zbType'     => isset($i['zb_type']) ? (int) $i['zb_type'] : null,   // 0 = Koordinator, 1 = Router
                'mode'       => isset($i['coord_mode']) ? (int) $i['coord_mode'] : null, // 0 = LAN, 1 = WLAN, 2 = USB
                'ethernet'   => isset($s['ethernet']) ? (bool) $s['ethernet'] : null,
                'espTemp'    => isset($s['esp32_temp']) ? round((float) $s['esp32_temp'], 1) : null,
                'zbTemp'     => isset($s['zb_temp']) ? round((float) $s['zb_temp'], 1) : null,
                'uptime'     => isset($s['uptime']) ? (int) $s['uptime'] : null,
                'sockUptime' => isset($s['socket_uptime']) ? (int) $s['socket_uptime'] : null,
                'clients'    => null
            ];
            $m = (string) $this->HttpGet($host, '/metrics');
            if (preg_match('/smlight_socket_clients\{\}\s+(\d+)/', $m, $r)) {
                $c['clients'] = (int) $r[1];
            }
        }
        $this->WriteAttributeString('Coordinator', json_encode($c));
        // nach einem Neustart schneller nachfragen, bis er wieder antwortet
        $fast = $this->ReadAttributeInteger('CoordRestartAt') > 0 && time() - $this->ReadAttributeInteger('CoordRestartAt') < 180;
        $this->SetTimerInterval('CoordinatorUpdate', ($fast && !($c['ok'] && ($c['uptime'] ?? 1e9) < 300) ? 10 : max(10, $this->ReadPropertyInteger('CoordinatorInterval'))) * 1000);
        $this->Push();
        return $c['ok'];
    }

    private function RestartCoordinator(): void
    {
        $host = $this->CoordHost();
        if ($host === '') {
            return;
        }
        $this->WriteAttributeInteger('CoordRestartAt', time());
        $this->LogMessage('Neustart des Zigbee-Koordinators ' . $host . ' über die Kachel angefordert', KL_NOTIFY);
        // SMLIGHT-API: action 4 = Befehl, cmd 3 = Neustart des Geräts (ESP32)
        $r = $this->HttpGet($host, '/api2?action=4&cmd=3');
        if ($r === null) {
            $this->LogMessage('Koordinator ' . $host . ': Neustart-Befehl ohne Antwort', KL_WARNING);
        }
        $this->SetTimerInterval('CoordinatorUpdate', 10 * 1000);
    }

    private function CoordHost(): string
    {
        return trim(preg_replace('#^https?://#i', '', trim($this->ReadPropertyString('CoordinatorHost'))), '/');
    }

    private function HttpGet(string $host, string $path): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => false, 'header' => "Accept: application/json\r\n"]]);
        $r = @file_get_contents('http://' . $host . $path, false, $ctx);
        return $r === false ? null : $r;
    }

    /* ---------- Zustand ---------- */

    private function BridgeOk(int $bid): bool
    {
        return $bid > 0 && @IPS_InstanceExists($bid) && IPS_GetInstance($bid)['ModuleInfo']['ModuleID'] === self::BRIDGE_GUID;
    }

    /** Ident => Variablen-ID der vorhandenen Bridge-Variablen */
    private function Vars(): array
    {
        $bid = $this->ReadPropertyInteger('BridgeID');
        $r = [];
        if (!$this->BridgeOk($bid)) {
            return $r;
        }
        foreach (self::IDENTS as $ident) {
            $vid = @IPS_GetObjectIDByIdent($ident, $bid);
            if ($vid !== false && IPS_VariableExists($vid)) {
                $r[$ident] = $vid;
            }
        }
        return $r;
    }

    private function Push(): void
    {
        $this->UpdateVisualizationValue(json_encode(['design' => $this->DesignCSS()] + $this->BuildState()));
    }

    private function BuildState(): array
    {
        $bid = $this->ReadPropertyInteger('BridgeID');
        if (!$this->BridgeOk($bid)) {
            return ['error' => 'Keine Zigbee2MQTT-Bridge ausgewählt'];
        }
        $v = $this->Vars();
        $val = fn (string $i) => isset($v[$i]) ? GetValue($v[$i]) : null;
        $now = time();

        $s = [
            'error'     => '',
            'now'       => $now,
            'online'    => $val('state') === null ? null : (bool) $val('state'),
            'since'     => isset($v['state']) ? IPS_GetVariable($v['state'])['VariableChanged'] : 0,
            'instOk'    => IPS_GetInstance($bid)['InstanceStatus'] === IS_ACTIVE,
            'version'   => (string) ($val('version') ?? ''),
            'zh'        => (string) ($val('zigbee_herdsman') ?? ''),
            'zhc'       => (string) ($val('zigbee_herdsman_converters') ?? ''),
            'channel'   => $val('network_channel') === null ? null : (int) $val('network_channel'),
            'ext'       => [
                'loaded'  => (bool) ($val('extension_loaded') ?? false),
                'version' => (string) ($val('extension_version') ?? ''),
                'current' => (bool) ($val('extension_is_current') ?? false)
            ],
            'logLevel'  => (string) ($val('log_level') ?? ''),
            'canLog'    => isset($v['log_level']),
            'canJoin'   => isset($v['permit_join']),
            'canRestart'=> isset($v['restart_request']),
            'restartRequired' => (bool) ($val('restart_required') ?? false),
            'restartAt' => $this->ReadAttributeInteger('RestartAt'),
            'join'      => false,
            'joinEnd'   => 0,
            'devices'   => $this->DeviceCount($bid),
            'coord'     => null,
            'newDevices'=> $this->NewForTile(),
            'listening' => IPS_GetInstance($this->InstanceID)['ConnectionID'] === IPS_GetInstance($bid)['ConnectionID']
        ];
        if ($this->CoordHost() !== '') {
            $c = json_decode($this->ReadAttributeString('Coordinator'), true) ?: [];
            $c['host'] = $this->CoordHost();
            $c['restartAt'] = $this->ReadAttributeInteger('CoordRestartAt');
            $s['coord'] = $c;
        }

        if (isset($v['permit_join']) && GetValue($v['permit_join'])) {
            $s['join'] = true;
            $t = (int) ($val('permit_join_timeout') ?? 0);
            $start = IPS_GetVariable($v['permit_join'])['VariableChanged'];
            if ($t > 0) {
                $s['joinEnd'] = IPS_GetVariable($v['permit_join_timeout'])['VariableUpdated'] + $t;
            } elseif ($this->ReadPropertyInteger('PermitJoinTime') > 0) {
                $s['joinEnd'] = $start + $this->ReadPropertyInteger('PermitJoinTime');
            }
            $s['joinStart'] = $start;
        }
        return $s;
    }

    /** Anzahl der Zigbee2MQTT-Geräteinstanzen am selben MQTT-Server wie die Bridge */
    private function DeviceCount(int $bid): int
    {
        $parent = IPS_GetInstance($bid)['ConnectionID'];
        $n = 0;
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_GUID) as $id) {
            if (IPS_GetInstance($id)['ConnectionID'] === $parent) {
                $n++;
            }
        }
        return $n;
    }
}
