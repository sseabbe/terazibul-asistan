# Terazibul ürün danışmanı (AI asistan)

[terazibul.com](https://terazibul.com) için geliştirdiğim yapay zekâ destekli ürün danışmanı. Ziyaretçinin ihtiyacını sohbetle anlar, sitedeki gerçek katalogdan uygun teraziyi bulur ve teknik soruları yalnızca doğrulanabilen bilgiyle yanıtlar.

> Bu depo canlı sitenin asistanla ilgili kodundan alınmış bir kesittir. Sitenin geri kalanına (veritabanı, çatı, yönetim paneli) bağlı olduğu için tek başına çalışmaz. Amaç mimariyi ve yaklaşımı göstermektir.

## Ne yapıyor

- **İhtiyacı soruyla netleştiriyor.** Kapasite, hassasiyet, kullanım alanı (kuyumcu, laboratuvar, endüstri) gibi bilgileri konuşma içinde topluyor, eksik olanı soruyor.
- **Ürünleri gerçek katalogdan getiriyor.** Model ürün uydurmuyor. Arama sitenin kendi filtre altyapısıyla yapılıyor, sohbetteki filtreler ürün listeleme sayfasıyla senkron.
- **Teknik iddiaları doğruluyor.** Bir modelin teknik özelliği, o modelin yapılandırılmış verisinde yoksa cevapta kalmıyor. Fiyat ve stok yalnız katalog alanlarından geliyor.
- **Hata kodlarını tanıyor.** Kullanıcı ekranda gördüğü kodu yazınca ilgili marka ve seri için hata açıklamasına yönlendiriyor.
- **Gerektiğinde insana devrediyor.** Satın alma ya da servis anında WhatsApp ve telefon desteğine geçiş öneriyor.
- **Kişisel veriyi saklamıyor.** Konuşma analizi için tutulan metinlerden kişisel veri ayıklanıyor, ham konuşma saklanmıyor.

## Mimari

| Klasör | Görev |
|---|---|
| `app/Services/AssistantV2/` | Ana danışman: niyet analizi → durum (state) → katalog araması → kanıt doğrulama → yanıt. RAG dizini, kategori profilleri, web araması (yalnız üretici alan adları), devir, konuşma raporları. |
| `app/Services/AssistantV3/` | Araç çağırma (tool calling) tabanlı yeni sürüm. Model metni ve gösterilecek ürünleri seçiyor, bağlantı, fiyat ve butonlar sunucuda doğrulanıyor. |
| `app/Services/ProductAssistant/` | İlk demo akışı: niyet → veritabanı araması → sınırlı bağlam → cevap doğrulama. |
| `app/Controllers/` | Sitedeki sohbet uç noktaları ve yönetim panelindeki konuşma ekranları. |
| `public/assets/` | Sohbet arayüzü (JS ve CSS). |
| `scripts/` | Gerçek kullanım senaryolarından türetilmiş regresyon testleri (konuşma, filtre, kanıt, takip soruları, kuyumcu listeleri vb.). |
| `core/Console/Commands/` | RAG dizini oluşturma, test ve rapor komutları. |

**Teknoloji:** PHP 8, MySQL, OpenAI API (Responses), vanilla JavaScript.

## Kapsam

Yaklaşık 31.000 satır PHP. Eylül–Kasım 2026 arasında geliştirildi.

---

**English summary:** An AI product advisor for terazibul.com, a Turkish precision-scale shop. It clarifies the visitor's needs in conversation, pulls matching products only from the real catalog (no invented products), strips any technical claim that the model's structured data can't back up, recognises scale error codes, and hands off to a human over WhatsApp when needed. Conversation analytics are stored with personal data redacted. This repository is an excerpt of the live codebase and does not run on its own.
