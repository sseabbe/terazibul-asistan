@layout('back/layouts/app')
@section('title', $title)
@section('breadcrumb')
<span>Müşteri &amp; İletişim</span><span class="sep">/</span><span>Asistan</span>
@endsection
@section('content')
<div class="page-header<?php echo $dashboardUrl !== '' ? ' page-header--with-back' : ''; ?>">
    <?php if ($dashboardUrl !== ''): ?>
    <a href="<?php echo e($dashboardUrl); ?>" class="page-header-back btn btn-outline btn-icon-only" aria-label="Geri" title="Geri">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <?php endif; ?>
    <div class="page-header-text">
        <h1 class="page-title">Asistan</h1>
        <p class="page-subtitle"><?php echo e($dayLabel); ?> · <?php echo count($conversations); ?> sohbet</p>
    </div>
</div>

<?php
$_asWait = 0;
try { $_asWait = \App\Services\ProductAssistant\AssistantSupport::waitingCount(); } catch (\Throwable) {}
?>
<div class="tb-toolbar mb-16">
<nav class="as-switch" aria-label="Asistan">
    <a href="<?php echo e(url('/admin/assistant-chats')); ?>" class="as-switch__a is-on" aria-current="page">Sohbetler</a>
    <a href="<?php echo e(url('/admin/assistant-messages')); ?>" class="as-switch__a">Bırakılan Mesajlar<?php if ($_asWait > 0): ?> <span class="as-switch__n"><?php echo (int) $_asWait; ?></span><?php endif; ?></a>
</nav>

<nav class="seg-tabs" aria-label="Tarih aralığı">
        <?php
        $chatTabs = ['1' => 'Bugün', '7' => 'Son 7 gün', '14' => 'Son 14 gün', '30' => 'Son 30 gün', 'all' => 'Tümü'];
        foreach ($chatTabs as $key => $tabLabel):
            $href = url('/admin/assistant-chats' . ($key === 'all' ? '?date=all' : '?son=' . $key));
            $isActive = $key === 'all' ? ($mode ?? '') === 'all' : (($mode ?? '') === 'range' && (int) ($days ?? 0) === (int) $key);
        ?>
        <a href="<?php echo e($href); ?>" class="<?php echo $isActive ? 'is-active' : ''; ?>"><?php echo e($tabLabel); ?></a>
        <?php endforeach; ?>
</nav>
</div>

<?php if (empty($conversations)): ?>
<div class="card">
    <div class="card-body">
        <p class="text-muted" style="margin:0">Bu aralıkta kayıtlı sohbet yok.</p>
    </div>
</div>
<?php else: ?>
<div class="chat-grid">
    <?php foreach ($conversations as $index => $conversation): ?>
    <details class="card chat-item">
        <summary class="card-header">
            <?php $__first = trim((string) ($conversation['turns'][0]['user_message_redacted'] ?? '')); $__first = preg_replace('/\s+/u', ' ', $__first) ?? $__first; ?>
            <h3 class="card-title" style="margin:0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo e($__first); ?>"><?php echo e($__first !== '' ? (mb_strlen($__first) > 70 ? mb_substr($__first, 0, 70) . '…' : $__first) : 'Sohbet ' . ($index + 1)); ?></h3>
            <span class="text-muted chat-item__meta"><?php echo !empty($conversation['started_at']) ? e(date('d.m.Y H:i', strtotime((string) $conversation['started_at']))) : ''; ?> · <?php echo count($conversation['turns']); ?> soru</span>
            <svg class="chat-item__chev" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6"/></svg>
        </summary>
        <div class="card-body">
            <?php foreach ($conversation['turns'] as $turn): ?>
            <p style="margin:0 0 4px;font-size:12px;opacity:.55;">Ziyaretçi · <?php echo !empty($turn['created_at']) ? e(date('d.m.Y H:i', strtotime((string) $turn['created_at']))) : ''; ?></p>
            <p style="margin:0 0 12px;"><?php echo nl2br(e((string) ($turn['user_message_redacted'] ?? ''))); ?></p>
            <?php if (trim((string) ($turn['assistant_message_redacted'] ?? '')) !== ''): ?>
            <p style="margin:0 0 4px;font-size:12px;opacity:.55;">Asistan</p>
            <p style="margin:0 0 20px;"><?php echo nl2br(e((string) ($turn['assistant_message_redacted'] ?? ''))); ?></p>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<style>
.chat-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}
.chat-grid > .card{margin:0;min-width:0}
.chat-item>summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:4px 12px}
.chat-item>summary::-webkit-details-marker{display:none}
.chat-item>summary .card-title{grid-column:1}
.chat-item__meta{grid-column:1;font-size:13px}
.chat-item__chev{grid-column:2;grid-row:1 / span 2;color:var(--color-muted,#8A889C);transition:transform .15s}
.chat-item[open] .chat-item__chev{transform:rotate(180deg)}
.chat-item:not([open])>summary{border-bottom:0}
@media (max-width:1100px){.chat-grid{grid-template-columns:1fr}}
</style>
@endsection
