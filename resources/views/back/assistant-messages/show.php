@layout('back/layouts/app')
@section('title', $title)
@section('breadcrumb')
<span>Müşteri &amp; İletişim</span><span class="sep">/</span>
<a href="<?php echo url('/admin/assistant-messages'); ?>">Asistan</a>
<span class="sep">/</span><span>Konuşma</span>
@endsection
@section('content')
<div class="page-header page-header--with-back">
    <a href="<?php echo url('/admin/assistant-messages'); ?>" class="page-header-back btn btn-outline btn-icon-only" aria-label="Geri" title="Geri">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <div class="page-header-text">
        <h1 class="page-title"><?php echo e((string) ($thread['question'] ?? 'Konuşma')); ?></h1>
        <p class="page-subtitle"><?php echo e(\App\Services\ProductAssistant\AssistantSupport::ago((string) ($thread['created_at'] ?? ''))); ?></p>
    </div>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success mb-24"><?php echo e($success); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger mb-24"><?php echo e($error); ?></div>
<?php endif; ?>

<div style="max-width:720px;">
    <?php
    $phone = trim((string) ($context['phone'] ?? ''));
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($phoneDigits, '0')) {
        $phoneDigits = '9' . $phoneDigits;
    }
    $products = is_array($context['products'] ?? null) ? $context['products'] : [];
    if ($phone !== ''):
    ?>
    <p class="text-muted" style="margin:0 0 8px;font-size:13px">Telefon: <strong style="color:#0E0D22"><?php echo e($phone); ?></strong></p>
    @include('back/partials/contact-actions', ['ca' => ['phone' => $phone]])
    <?php endif; ?>
    <?php
    if ($products !== []):
    ?>
    <div class="card mb-24">
        <div class="card-header"><h3 class="card-title">İlgili ürünler</h3></div>
        <div class="card-body">
            <?php foreach ($products as $product): ?>
            <p><?php echo e((string) ($product['name'] ?? '')); ?></p>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-24">
        <div class="card-header"><h3 class="card-title">Konuşma</h3></div>
        <div class="card-body">
            <?php
            $senders = ['user' => 'Kullanıcı', 'assistant' => 'Asistan', 'agent' => 'Ekip'];
            foreach ($messages as $message):
            ?>
            <p style="margin:0 0 4px;font-size:12px;opacity:.55;"><?php echo e($senders[$message['sender_type']] ?? $message['sender_type']); ?> · <?php echo e((string) ($message['created_at'] ?? '')); ?></p>
            <p style="margin:0 0 16px;"><?php echo e((string) $message['body']); ?></p>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (($thread['status'] ?? '') !== 'closed'): ?>
    <div class="card mb-24">
        <div class="card-header"><h3 class="card-title">Cevap yaz</h3></div>
        <div class="card-body">
            <form method="POST" action="<?php echo e(url('/admin/assistant-messages/' . (int) $thread['id'] . '/reply')); ?>">
                <?php echo csrf_field(); ?>
                <textarea name="body" class="form-control" rows="5" maxlength="4000" required placeholder="Kullanıcıya görünecek cevap"></textarea>
                <button type="submit" class="btn btn-primary" style="margin-top:12px;"><svg class="btn-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14l11 -11"/><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5"/></svg> Cevabı ilet</button>
            </form>
        </div>
    </div>
    <form method="POST" action="<?php echo e(url('/admin/assistant-messages/' . (int) $thread['id'] . '/close')); ?>">
        <?php echo csrf_field(); ?>
        <button type="submit" class="btn btn-outline"><svg class="btn-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg> Konuşmayı kapat</button>
    </form>
    <?php endif; ?>
</div>
@endsection
