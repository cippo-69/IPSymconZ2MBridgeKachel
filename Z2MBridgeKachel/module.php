<?php

declare(strict_types=1);

/**
 * Z2M Bridge Kachel (HTML-SDK)
 *
 * Bedienkachel für eine Instanz „Zigbee2MQTT Bridge“ (Modul Schnittcher/IPS-Zigbee2MQTT):
 * Status, Versionen, Netzwerkkanal, Anzahl Geräte, Anlernen (permit_join) mit Countdown,
 * Protokollstufe und Neustart (mit Rückfrage).
 *
 * Geschaltet wird über die Aktionen der Bridge-Variablen (RequestAction), also genau wie in der Konsole.
 */
class Z2MBridgeKachel extends IPSModuleStrict
{
    private const BRIDGE_GUID = '{00160D82-9E2F-D1BD-6D0B-952F945332C5}';
    private const DEVICE_GUID = '{E5BB36C6-A70B-EB23-3716-9151A09AC8A2}';
    private const LOG_LEVELS = ['error', 'warning', 'info', 'debug'];

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
        $this->RegisterPropertyInteger('AccentColor', 0x5ECAAB); // Türkis der Symcon-Kachel-Visu
        $this->RegisterPropertyString('CoordinatorHost', '');    // optional: SMLIGHT-Koordinator (SLZB-06 u. a.), IP oder Hostname
        $this->RegisterPropertyInteger('CoordinatorInterval', 60); // Abfrage in s

        $this->RegisterAttributeString('Watched', '[]');
        $this->RegisterAttributeInteger('RestartAt', 0);
        $this->RegisterAttributeString('Coordinator', '{}');
        $this->RegisterAttributeInteger('CoordRestartAt', 0);

        $this->RegisterTimer('CoordinatorUpdate', 0, 'Z2MK_UpdateCoordinator($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

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

    public function GetVisualizationTile(): string
    {
        $accent = sprintf('#%06X', $this->ReadPropertyInteger('AccentColor') & 0xFFFFFF);
        $initial = '<script>handleMessage(' . json_encode(json_encode($this->BuildState())) . ');</script>';
        return file_get_contents(__DIR__ . '/module.html') . '<style>:root{--zb-accent:' . $accent . ';}</style>' . $initial;
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
            case 'Refresh':
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->Push();
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
        $this->UpdateVisualizationValue(json_encode($this->BuildState()));
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
            'coord'     => null
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
