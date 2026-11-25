<?php
// ASSISTANT_ENGINE=v2 ile eski motor ve eski pencereye anında geri dönülebilir
$_tbaEngine = strtolower(trim((string) env('ASSISTANT_ENGINE', 'v3')));
$_tblEndpoint = $_tbaEngine === 'v2' ? url('/demo/urun-asistani/mesaj') : url('/asistan/mesaj');
$_tblJsFs = base_path('public/assets/frontend/scripts/product-assistant-launcher.js');
$_tblJsHref = asset('assets/frontend/scripts/product-assistant-launcher.js') . '?v=' . (string) (is_file($_tblJsFs) ? filemtime($_tblJsFs) : '1');
$_tblIconFs = base_path('public/assets/frontend/images/assistant-icon.svg');
$_tblIconHref = asset('assets/frontend/images/assistant-icon.svg') . '?v=' . (string) (is_file($_tblIconFs) ? filemtime($_tblIconFs) : '1');
?>
<?php if ($_tbaEngine !== 'v2'): ?>
<?php
$_tbaJsFs = base_path('public/assets/frontend/scripts/assistant-v3.js');
$_tbaCssFs = base_path('public/assets/frontend/styles/css/assistant-v3.css');
$_tbaWa = (string) \App\Models\Setting::get('whatsapp_number', '');
$_tbaWaDigits = preg_replace('/\D+/', '', $_tbaWa) ?? '';
if ($_tbaWaDigits !== '' && str_starts_with($_tbaWaDigits, '0')) {
    $_tbaWaDigits = '90' . substr($_tbaWaDigits, 1);
}
?>
<link rel="stylesheet" href="<?php echo e(asset('assets/frontend/styles/css/assistant-v3.css') . '?v=' . (is_file($_tbaCssFs) ? filemtime($_tbaCssFs) : '1')); ?>">
<?php $_tbaTel = format_tr_phone_tel((string) \App\Models\Setting::get('site_phone', '')); ?>
<div class="tbl" data-tba data-endpoint="<?php echo e($_tblEndpoint); ?>" data-wa="<?php echo e($_tbaWaDigits !== '' ? 'https://wa.me/' . $_tbaWaDigits : ''); ?>" data-phone="<?php echo e($_tbaTel !== '' && $_tbaTel !== '+' ? 'tel:' . $_tbaTel : ''); ?>">
  <section class="tba" id="tba-panel" hidden role="dialog" aria-modal="false" aria-labelledby="tba-title">
    <header class="tba-head">
      <span class="tba-head__avatar"><img src="<?php echo e($_tblIconHref); ?>" width="40" height="40" alt=""></span>
      <div class="tba-head__text">
        <h2 class="tba-head__title" id="tba-title">Terazibul Destek Hattı</h2>
        <p class="tba-head__sub">Yapay zeka asistanı</p>
      </div>
      <button type="button" class="tba-icon-btn" data-tba-reset aria-label="Yeni sohbet" title="Yeni sohbet"><svg class="ti ti-rotate" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19.95 11a8 8 0 1 0 -.5 4m.5 5v-5h-5" /></svg></button>
      <button type="button" class="tba-icon-btn" data-tba-close aria-label="Kapat" title="Kapat"><svg class="ti ti-x" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg></button>
    </header>
    <div class="tba-log" aria-live="polite"></div>
    <form class="tba-form" autocomplete="off">
      <label class="tba-sr" for="tba-input">Mesajınız</label>
      <div class="tba-field">
        <textarea id="tba-input" class="tba-input" rows="1" maxlength="600" enterkeyhint="send" placeholder="Bir şey sorun…"></textarea>
        <button type="submit" class="tba-send" aria-label="Gönder"><svg class="ti ti-arrow-up" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5l0 14" /><path d="M18 11l-6 -6" /><path d="M6 11l6 -6" /></svg></button>
      </div>
    </form>
  </section>
  <div class="tbl-dock">
    <button type="button" class="tbl-label" hidden data-analytics-ignore="1"></button>
    <button type="button" class="tbl-btn" data-analytics-ignore="1" aria-expanded="false" aria-controls="tba-panel" aria-label="Terazibul Asistanı">
      <img class="tbl-mark" src="<?php echo e($_tblIconHref); ?>" width="56" height="56" alt="">
    </button>
  </div>
</div>
<script defer src="<?php echo e(asset('assets/frontend/scripts/assistant-v3.js') . '?v=' . (is_file($_tbaJsFs) ? filemtime($_tbaJsFs) : '1')); ?>"></script>
<?php else: ?>
<div class="tbl" data-tb-assistant data-endpoint="<?php echo e($_tblEndpoint); ?>" data-support="<?php echo e(url('/demo/urun-asistani/destek')); ?>">
  <section class="tbl-panel" id="tbl-panel" hidden role="dialog" aria-label="Terazibul Asistanı">
    <header class="tbl-head">
      <img class="tbl-head-mark" src="<?php echo e($_tblIconHref); ?>" width="40" height="40" alt="">
      <h2>Terazibul Destek Hattı</h2>
      <div class="tbl-head-tools">
        <button type="button" class="tbl-end">Sonlandır</button>
        <button type="button" class="tbl-close" aria-label="Kapat">&times;</button>
        <div class="tbl-end-confirm" hidden>
          <p class="tbl-end-confirm__text">Sohbeti sonlandırmak istediğinize emin misiniz?</p>
          <div class="tbl-end-confirm__actions">
            <button type="button" class="tbl-end-confirm__btn tbl-end-confirm__btn--no">Hayır</button>
            <button type="button" class="tbl-end-confirm__btn tbl-end-confirm__btn--yes">Evet</button>
          </div>
        </div>
      </div>
    </header>
    <div class="tbl-log" aria-live="polite">
      <div class="tbl-row tbl-row--bot">
        <div class="tbl-bubble">
          <p>Merhaba,<br>Size nasıl yardımcı olabilirim?</p>
        </div>
      </div>
    </div>
    <form class="tbl-form">
      <label class="tbl-sr" for="tbl-input">Sorunuzu yazın</label>
      <textarea id="tbl-input" class="tbl-input" name="message" rows="1" maxlength="500" autocomplete="off" enterkeyhint="send" placeholder="İhtiyacınızı yazın"></textarea>
      <button type="submit">Gönder</button>
    </form>
  </section>
  <div class="tbl-dock">
    <button type="button" class="tbl-label" hidden data-analytics-ignore="1"></button>
    <button type="button" class="tbl-btn" data-analytics-ignore="1" aria-expanded="false" aria-controls="tbl-panel" aria-label="Terazibul Asistanı">
      <img class="tbl-mark" src="<?php echo e($_tblIconHref); ?>" width="56" height="56" alt="">
    </button>
  </div>
</div>
<script defer src="<?php echo e($_tblJsHref); ?>"></script>
<?php endif; ?>
