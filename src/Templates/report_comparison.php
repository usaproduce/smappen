<?php
/** @var array $areas */
/** @var array $demographicsByArea */ // keyed by area id
/** @var string $generated_at */
$num = fn($n) => $n === null ? '—' : number_format((float)$n);
$money = fn($n) => $n === null ? '—' : '$' . number_format((float)$n);
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Area Comparison</title>
<style>
  body { font-family: Arial, sans-serif; color: #1f2937; font-size: 11pt; }
  h1 { color: #1e3a5f; font-size: 22pt; }
  table { width: 100%; border-collapse: collapse; margin-top: 12pt; }
  th, td { padding: 6pt 8pt; border-bottom: 1px solid #e5e7eb; text-align: left; }
  th { background: #f1f5f9; color: #1e3a5f; }
  .label { background: #f8fafc; font-weight: 600; }
</style></head>
<body>
<h1>Area Comparison</h1>
<div style="color:#475569; font-size:9pt;">Generated <?= htmlspecialchars($generated_at) ?></div>
<table>
  <tr>
    <th>Metric</th>
    <?php foreach ($areas as $a): ?>
      <th><?= htmlspecialchars($a['name']) ?></th>
    <?php endforeach; ?>
  </tr>
  <?php
    $metrics = [
        ['Population', fn($d) => $num($d['population']['total'] ?? 0)],
        ['Median Income', fn($d) => $money($d['income']['median_household'] ?? null)],
        ['Unemployment Rate', fn($d) => ($d['employment']['unemployment_rate'] ?? 0) . '%'],
        ['Housing Units', fn($d) => $num($d['housing']['total_units'] ?? 0)],
        ['Median Home Value', fn($d) => $money($d['housing']['median_value'] ?? null)],
    ];
    foreach ($metrics as [$label, $extract]):
  ?>
    <tr>
      <td class="label"><?= $label ?></td>
      <?php foreach ($areas as $a): $d = $demographicsByArea[$a['id']] ?? []; ?>
        <td><?= $extract($d) ?></td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
</table>
</body></html>
