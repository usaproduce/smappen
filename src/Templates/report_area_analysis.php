<?php
/** @var array $area */
/** @var array $demographics */
/** @var array $pois */
/** @var ?string $map_path */
/** @var string $title */
/** @var string $generated_at */
$d = $demographics;
$pop = $d['population'] ?? [];
$age = $d['age'] ?? [];
$inc = $d['income'] ?? [];
$emp = $d['employment'] ?? [];
$hou = $d['housing'] ?? [];
$num = fn($n) => $n === null ? '—' : number_format((float)$n);
$money = fn($n) => $n === null ? '—' : '$' . number_format((float)$n);
$pct = fn($a, $b) => $b > 0 ? round(($a / $b) * 100, 1) . '%' : '—';
$totalPop = (int)($pop['total'] ?? 0);
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title><?= htmlspecialchars($title) ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 11pt; }
  h1 { color: #1e3a5f; font-size: 22pt; margin: 0 0 4pt; }
  h2 { color: #1e3a5f; font-size: 13pt; border-bottom: 1px solid #cbd5e1; padding-bottom: 4pt; margin-top: 16pt; }
  .meta { color: #475569; font-size: 9pt; }
  table { width: 100%; border-collapse: collapse; margin: 6pt 0; }
  th, td { padding: 5pt 8pt; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 10pt; }
  th { background: #f1f5f9; color: #1e3a5f; }
  tr:nth-child(even) td { background: #f8fafc; }
  .big { font-size: 22pt; font-weight: 700; color: #1e3a5f; }
  .green { color: #047857; }
  .row { display: table; width: 100%; }
  .cell { display: table-cell; padding: 6pt 8pt; vertical-align: top; }
  .stat-box { background: #f1f5f9; border-left: 4px solid #6B4EFF; padding: 8pt; margin: 6pt 0; }
  .footer { color: #94a3b8; font-size: 8pt; text-align: center; margin-top: 24pt; }
</style></head>
<body>

<h1><?= htmlspecialchars($title) ?></h1>
<div class="meta">Generated <?= htmlspecialchars($generated_at) ?></div>

<?php if (!empty($map_path) && file_exists($map_path)): ?>
<div style="text-align:center; margin:10pt 0;">
  <img src="<?= htmlspecialchars($map_path) ?>" style="max-width:100%;" />
</div>
<?php endif; ?>

<div class="stat-box">
  <b><?= htmlspecialchars($area['name']) ?></b><br>
  <span class="meta">
    Type: <?= htmlspecialchars($area['area_type']) ?>
    <?php if (!empty($area['travel_mode'])): ?>
      · Mode: <?= htmlspecialchars($area['travel_mode']) ?>
    <?php endif; ?>
    <?php if (!empty($area['travel_time_minutes'])): ?>
      · Time: <?= (int)$area['travel_time_minutes'] ?> min
    <?php endif; ?>
    <?php if (!empty($area['center_address'])): ?>
      <br>Center: <?= htmlspecialchars($area['center_address']) ?>
    <?php endif; ?>
  </span>
</div>

<h2>Population</h2>
<div class="row">
  <div class="cell">
    <div class="big"><?= $num($totalPop) ?></div>
    <div class="meta">Total population</div>
  </div>
  <div class="cell">
    <div><b><?= $num($pop['density_per_sq_km'] ?? 0) ?></b> per sq km</div>
    <div class="meta">Density</div>
  </div>
</div>
<table>
  <tr><th>Gender</th><th>Count</th><th>%</th></tr>
  <tr><td>Male</td><td><?= $num($pop['male'] ?? 0) ?></td><td><?= $pct((int)($pop['male'] ?? 0), $totalPop) ?></td></tr>
  <tr><td>Female</td><td><?= $num($pop['female'] ?? 0) ?></td><td><?= $pct((int)($pop['female'] ?? 0), $totalPop) ?></td></tr>
</table>

<h2>Age Distribution</h2>
<table>
  <tr><th>Bracket</th><th>Count</th><th>%</th></tr>
  <?php foreach (['under_18' => 'Under 18', '18_to_34' => '18–34', '35_to_54' => '35–54', '55_to_64' => '55–64', '65_plus' => '65+'] as $k => $label): ?>
    <tr><td><?= $label ?></td><td><?= $num($age[$k] ?? 0) ?></td><td><?= $pct((int)($age[$k] ?? 0), $totalPop) ?></td></tr>
  <?php endforeach; ?>
</table>

<h2>Income</h2>
<div class="big green"><?= $money($inc['median_household'] ?? null) ?></div>
<div class="meta">Median household income</div>
<table style="margin-top:8pt;">
  <tr><th>Bracket</th><th>Households</th></tr>
  <?php $brackets = $inc['brackets'] ?? []; foreach (['under_25k' => '< $25K', '25k_to_50k' => '$25K–$50K', '50k_to_75k' => '$50K–$75K', '75k_to_100k' => '$75K–$100K', '100k_plus' => '$100K+'] as $k => $label): ?>
    <tr><td><?= $label ?></td><td><?= $num($brackets[$k] ?? 0) ?></td></tr>
  <?php endforeach; ?>
</table>

<h2>Employment</h2>
<table>
  <tr><th>Metric</th><th>Value</th></tr>
  <tr><td>Labor force</td><td><?= $num($emp['labor_force'] ?? 0) ?></td></tr>
  <tr><td>Unemployed</td><td><?= $num($emp['unemployed'] ?? 0) ?></td></tr>
  <tr><td>Unemployment rate</td><td><?= ($emp['unemployment_rate'] ?? 0) ?>%</td></tr>
</table>

<h2>Housing</h2>
<table>
  <tr><th>Metric</th><th>Value</th></tr>
  <tr><td>Total housing units</td><td><?= $num($hou['total_units'] ?? 0) ?></td></tr>
  <tr><td>Median home value</td><td><?= $money($hou['median_value'] ?? null) ?></td></tr>
</table>

<?php if (!empty($pois)): ?>
<h2>Top Businesses</h2>
<table>
  <tr><th>Name</th><th>Rating</th><th>Address</th></tr>
  <?php
    usort($pois, fn($a, $b) => ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0));
    foreach (array_slice($pois, 0, 10) as $p):
  ?>
    <tr>
      <td><?= htmlspecialchars($p['displayName']['text'] ?? '') ?></td>
      <td><?= isset($p['rating']) ? number_format((float)$p['rating'], 1) . ' ★ (' . ($p['userRatingCount'] ?? 0) . ')' : '—' ?></td>
      <td><?= htmlspecialchars($p['formattedAddress'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<div class="footer">Generated by Smappen · <?= htmlspecialchars($generated_at) ?></div>
</body></html>
