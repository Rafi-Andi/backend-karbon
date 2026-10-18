<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="10">
    <title>Monitoring Akses — KarbonKita</title>
    <style>
        :root {
            --bg: #0b1220;
            --panel: #111c31;
            --panel-2: #16233d;
            --border: #22314f;
            --text: #e6edf7;
            --muted: #8ba0bf;
            --green: #34d399;
            --blue: #60a5fa;
            --amber: #fbbf24;
            --red: #f87171;
            --purple: #c084fc;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: radial-gradient(1200px 600px at 20% -10%, #123324 0%, var(--bg) 55%);
            color: var(--text);
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            padding: 16px 26px;
            border-bottom: 1px solid var(--border);
            background: rgba(17, 28, 49, 0.78);
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .brand { display: flex; gap: 12px; align-items: center; }
        .logo { font-size: 26px; }
        h1 { margin: 0; font-size: 17px; }
        header p { margin: 3px 0 0; color: var(--muted); font-size: 11.5px; }
        .live {
            display: flex; align-items: center; gap: 8px;
            font-weight: 700; font-size: 12px; letter-spacing: .08em;
            color: var(--green);
            border: 1px solid rgba(52, 211, 153, .4);
            background: rgba(52, 211, 153, .08);
            padding: 6px 12px; border-radius: 999px;
        }
        .dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--green);
            animation: pulse 1.6s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(52, 211, 153, .6); }
            70% { box-shadow: 0 0 0 10px rgba(52, 211, 153, 0); }
            100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0); }
        }
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(168px, 1fr));
            gap: 12px;
            padding: 18px 26px 0;
        }
        .card {
            background: linear-gradient(180deg, var(--panel-2), var(--panel));
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px;
            display: flex; flex-direction: column; gap: 2px;
        }
        .card .k { color: var(--muted); font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; }
        .card b { font-size: 26px; }
        .card i { color: var(--muted); font-size: 11px; font-style: normal; }
        main {
            display: grid;
            grid-template-columns: minmax(0, 3fr) minmax(260px, 1fr);
            gap: 14px;
            padding: 18px 26px 30px;
            align-items: start;
        }
        @media (max-width: 980px) { main { grid-template-columns: 1fr; } }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px 18px;
        }
        .panel h2 { margin: 0 0 12px; font-size: 14px; }
        .table-wrap { overflow: auto; max-height: 72vh; }
        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        thead th {
            text-align: left; color: var(--muted);
            font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em;
            padding: 8px 10px; border-bottom: 1px solid var(--border);
            position: sticky; top: 0; background: var(--panel);
        }
        tbody td { padding: 9px 10px; border-bottom: 1px solid rgba(34, 49, 79, .55); vertical-align: top; }
        tbody tr:hover { background: rgba(96, 165, 250, .05); }
        .time { font-family: Consolas, monospace; color: var(--muted); white-space: nowrap; }
        .who { font-weight: 600; display: block; }
        .role { display: inline-block; margin-top: 3px; font-size: 10px; padding: 2px 8px; border-radius: 999px; border: 1px solid; }
        .role-warga { color: var(--green); border-color: rgba(52, 211, 153, .4); background: rgba(52, 211, 153, .08); }
        .role-mitra { color: var(--blue); border-color: rgba(96, 165, 250, .4); background: rgba(96, 165, 250, .08); }
        .role-admin { color: var(--purple); border-color: rgba(192, 132, 252, .4); background: rgba(192, 132, 252, .08); }
        .role-guest { color: var(--muted); border-color: var(--border); background: rgba(139, 160, 191, .08); }
        .action { font-weight: 600; }
        .path { margin-top: 3px; color: var(--muted); font-family: Consolas, monospace; font-size: 11px; }
        .method { display: inline-block; border-radius: 6px; padding: 1px 6px; font-size: 10px; font-weight: 700; }
        .m-get { color: var(--blue); background: rgba(96, 165, 250, .12); }
        .m-post { color: var(--green); background: rgba(52, 211, 153, .12); }
        .m-patch { color: var(--amber); background: rgba(251, 191, 36, .12); }
        .m-delete { color: var(--red); background: rgba(248, 113, 113, .12); }
        .device, .ip { color: var(--muted); font-family: Consolas, monospace; font-size: 11px; white-space: nowrap; }
        .status { display: inline-block; min-width: 38px; text-align: center; border-radius: 8px; padding: 3px 8px; font-weight: 700; font-size: 11px; }
        .st-ok { color: var(--green); background: rgba(52, 211, 153, .12); }
        .st-info { color: var(--blue); background: rgba(96, 165, 250, .12); }
        .st-warn { color: var(--amber); background: rgba(251, 191, 36, .12); }
        .st-err { color: var(--red); background: rgba(248, 113, 113, .12); }
        .top { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
        .top li { display: flex; justify-content: space-between; gap: 10px; align-items: center; border-bottom: 1px dashed rgba(34, 49, 79, .8); padding-bottom: 8px; }
        .top li:last-child { border-bottom: 0; padding-bottom: 0; }
        .t-label { display: block; font-size: 12.5px; font-weight: 600; }
        .t-path { display: block; margin-top: 2px; color: var(--muted); font-family: Consolas, monospace; font-size: 10.5px; }
        .top b { color: var(--green); font-size: 13px; white-space: nowrap; }
        .foot-note { color: var(--muted); font-size: 11px; margin: 14px 0 0; }
        .empty { color: var(--muted); text-align: center; padding: 18px 0; font-size: 12.5px; }
    </style>
</head>
<body>
@php
    $labelOf = fn ($log) => $log->route && isset($routeLabels[$log->route])
        ? $routeLabels[$log->route]
        : trim($log->method.' '.$log->path);

    $roleClass = fn (?string $role) => match ($role) {
        'admin' => 'role-admin',
        'mitra' => 'role-mitra',
        'warga' => 'role-warga',
        default => 'role-guest',
    };

    $statusClass = fn (int $status) => match (true) {
        $status >= 500 => 'st-err',
        $status >= 400 => 'st-warn',
        $status >= 300 => 'st-info',
        default => 'st-ok',
    };

    $deviceOf = function (?string $ua): string {
        if (! $ua) {
            return '—';
        }
        if (str_contains($ua, 'Dio') || str_contains($ua, 'Dart')) {
            return 'Aplikasi';
        }
        if (str_contains($ua, 'Mozilla')) {
            return 'Browser';
        }
        if (str_contains($ua, 'Xendit')) {
            return 'Xendit';
        }

        return \Illuminate\Support\Str::limit($ua, 26);
    };
@endphp

<header>
    <div class="brand">
        <span class="logo">🌱</span>
        <div>
            <h1>KarbonKita — Monitoring Akses</h1>
            <p>Diperbarui {{ $generatedAt }} • refresh otomatis tiap 10 detik</p>
        </div>
    </div>
    <div class="live"><span class="dot"></span> LIVE</div>
</header>

<section class="cards">
    <div class="card">
        <span class="k">Total akses hari ini</span>
        <b>{{ number_format($summary['accesses_today'], 0, ',', '.') }}</b>
        <i>semua endpoint</i>
    </div>
    <div class="card">
        <span class="k">Pengguna unik</span>
        <b>{{ number_format($summary['unique_users_today'], 0, ',', '.') }}</b>
        <i>sudah login hari ini</i>
    </div>
    <div class="card">
        <span class="k">Akses tanpa login</span>
        <b>{{ number_format($summary['guests_today'], 0, ',', '.') }}</b>
        <i>tamu / webhook</i>
    </div>
    <div class="card">
        <span class="k">Misi selesai</span>
        <b>{{ number_format($summary['missions_verified_today'], 0, ',', '.') }}</b>
        <i>diverifikasi hari ini</i>
    </div>
    <div class="card">
        <span class="k">Voucher ditukar</span>
        <b>{{ number_format($summary['claims_today'], 0, ',', '.') }}</b>
        <i>klaim warga</i>
    </div>
    <div class="card">
        <span class="k">Voucher dipakai</span>
        <b>{{ number_format($summary['redeems_today'], 0, ',', '.') }}</b>
        <i>redeem di mitra</i>
    </div>
</section>

<main>
    <section class="panel">
        <h2>Akses Terakhir</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Pengguna</th>
                    <th>Aksi</th>
                    <th>Perangkat</th>
                    <th>Status</th>
                    <th>IP</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="time">{{ $log->created_at->timezone('Asia/Jakarta')->format('d/m H:i:s') }}</td>
                        <td>
                            <span class="who">{{ $log->user_name ?: 'Tamu' }}</span>
                            <span class="role {{ $roleClass($log->user_role) }}">{{ $log->user_role ?: 'belum login' }}</span>
                        </td>
                        <td>
                            <div class="action">{{ $labelOf($log) }}</div>
                            <div class="path">
                                <span class="method m-{{ strtolower($log->method) }}">{{ $log->method }}</span>
                                {{ $log->path }}
                            </div>
                        </td>
                        <td class="device">{{ $deviceOf($log->user_agent) }}</td>
                        <td><span class="status {{ $statusClass($log->status) }}">{{ $log->status }}</span></td>
                        <td class="ip">{{ $log->ip ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="empty">Belum ada akses tercatat. Buka aplikasi untuk mulai mengisi log.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <aside class="panel">
        <h2>Endpoint Terpopuler (hari ini)</h2>
        <ol class="top">
            @forelse ($topEndpoints as $top)
                <li>
                    <div>
                        <span class="t-label">
                            {{ $top->route && isset($routeLabels[$top->route])
                                ? $routeLabels[$top->route]
                                : trim($top->method.' '.$top->path) }}
                        </span>
                        <span class="t-path">{{ $top->method }} {{ $top->path }}</span>
                    </div>
                    <b>{{ number_format($top->hits, 0, ',', '.') }}×</b>
                </li>
            @empty
                <li class="empty">Belum ada data hari ini.</li>
            @endforelse
        </ol>
        <p class="foot-note">
            Halaman ini untuk juri/demo — mencatat request API (siapa &amp;
            mengakses apa) secara live. Log disimpan maksimal 30 hari.
        </p>
    </aside>
</main>
</body>
</html>
