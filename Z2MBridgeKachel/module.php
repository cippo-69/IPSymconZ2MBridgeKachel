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

        $this->RegisterAttributeString('Watched', '[]');
        $this->RegisterAttributeInteger('RestartAt', 0);

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
            case 'Refresh':
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->Push();
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
            'devices'   => $this->DeviceCount($bid)
        ];

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
