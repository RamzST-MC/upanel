<?php
[$uname] = run('uname -a');
[$cpu] = run("lscpu | grep 'Model name' | cut -d: -f2");
[$mem] = run('free -h');
[$disk] = run('df -h /');
[$up] = run('uptime -p');
?>
<h1>Информация о сервере</h1>
<div class="card"><h2>Система</h2><pre class="term" style="height:auto;color:#ccc"><?= h(trim($uname)) ?>

CPU:<?= h(trim($cpu)) ?>
Uptime: <?= h(trim($up)) ?></pre></div>
<div class="card"><h2>Память</h2><pre class="term" style="height:auto;color:#ccc"><?= h($mem) ?></pre></div>
<div class="card"><h2>Диск</h2><pre class="term" style="height:auto;color:#ccc"><?= h($disk) ?></pre></div>
