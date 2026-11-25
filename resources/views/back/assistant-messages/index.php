@layout('back/layouts/app')
@section('title', $title)
@section('breadcrumb')
<span>Müşteri &amp; İletişim</span><span class="sep">/</span><span>Asistan</span>
@endsection
@section('content')
<div class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Asistan</h1>
        <p class="page-subtitle"><?php echo (int) ($waiting ?? 0); ?> bekleyen konuşma</p>
    </div>
</div>

<?php
$_asWait = 0;
try { $_asWait = \App\Services\ProductAssistant\AssistantSupport::waitingCount(); } catch (\Throwable) {}
?>
<div class="tb-toolbar mb-16">
<nav class="as-switch" aria-label="Asistan">
    <a href="<?php echo e(url('/admin/assistant-chats')); ?>" class="as-switch__a">Sohbetler</a>
    <a href="<?php echo e(url('/admin/assistant-messages')); ?>" class="as-switch__a is-on" aria-current="page">Bırakılan Mesajlar<?php if ($_asWait > 0): ?> <span class="as-switch__n"><?php echo (int) $_asWait; ?></span><?php endif; ?></a>
</nav>

<?php if (!empty($success)): ?>
<div class="alert alert-success mb-24"><?php echo e($success); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger mb-24"><?php echo e($error); ?></div>
<?php endif; ?>

<nav class="seg-tabs" aria-label="Durum">
        <?php
        $tabs = ['' => 'Tümü', 'waiting' => 'Bekliyor', 'answered' => 'Cevaplanan', 'closed' => 'Kapatılan'];
        foreach ($tabs as $key => $label):
            $href = url('/admin/assistant-messages' . ($key !== '' ? '?status=' . $key : ''));
        ?>
        <a href="<?php echo e($href); ?>" class="<?php echo ($status ?? '') === $key ? 'is-active' : ''; ?>"><?php echo e($label); ?></a>
        <?php endforeach; ?>
</nav>
</div>

<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>Durum</th>
                <th>Soru</th>
                <th>Ürün</th>
                <th>Bekleme</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($threads)): ?>
            <tr><td colspan="4" class="text-center text-muted py-24">Henüz asistan mesajı yok. Sitedeki sohbet asistanında ziyaretçi "uzmana bağlan" dediğinde mesajı burada görünür.</td></tr>
            <?php else: ?>
            <?php foreach ($threads as $thread): ?>
            <?php
                $context = json_decode((string) ($thread['context'] ?? ''), true);
                $productName = '';
                if (is_array($context) && is_array($context['products'] ?? null) && isset($context['products'][0]['name'])) {
                    $productName = (string) $context['products'][0]['name'];
                }
                $labels = ['waiting' => 'Bekliyor', 'answered' => 'Cevaplandı', 'closed' => 'Kapatıldı'];
            ?>
            <tr>
                <td><?php echo e($labels[$thread['status']] ?? $thread['status']); ?></td>
                <td>
                    <a href="<?php echo e(url('/admin/assistant-messages/' . (int) $thread['id'])); ?>"><?php echo e((string) ($thread['question'] ?: '—')); ?></a>
                </td>
                <td class="text-muted"><?php echo e($productName !== '' ? $productName : '—'); ?></td>
                <td class="text-muted" style="white-space:nowrap"><?php echo e(\App\Services\ProductAssistant\AssistantSupport::ago((string) ($thread['last_message_at'] ?? $thread['created_at'] ?? ''))); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
@endsection
