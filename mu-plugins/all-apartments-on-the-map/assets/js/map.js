(function ($) {
  "use strict";

  /* ======================================================
   * PRICE RESOLVER — BSBT CARDS (REAL DOM)
   * ====================================================== */

  function getPriceFromDOMByUrl(apartmentUrl) {
    if (!apartmentUrl) return '';

    // нормализуем URL (без trailing slash)
    const cleanUrl = apartmentUrl.replace(/\/$/, '');

    // ищем карточку, где есть ссылка на эту квартиру
    const cards = document.querySelectorAll('.bsbt-acc-card');

    for (const card of cards) {
      const link = card.querySelector('a[href]');
      if (!link) continue;

      const href = link.getAttribute('href').replace(/\/$/, '');

      if (href === cleanUrl) {
        const priceEl = card.querySelector('.mphb-price');
        if (priceEl) {
          return priceEl.textContent.trim();
        }
      }
    }

    return '';
  }

  /* ======================================================
   * MAP
   * ====================================================== */

  function runMap() {

    const mapEl = document.getElementById('aaotm-map');
    if (!mapEl || typeof google === 'undefined') return;

    const urlParams = new URLSearchParams(window.location.search);

    const searchData = {
      action: 'aaotm_get_apartments',
      check_in:  urlParams.getAll('mphb_check_in_date').filter(v => v !== ''),
      check_out: urlParams.getAll('mphb_check_out_date').filter(v => v !== ''),
      adults: urlParams.get('mphb_adults') || 1,
      apt_type: urlParams.get('mphb_attributes[apartment-type]') || ""
    };

    const map = new google.maps.Map(mapEl, {
      center: { lat: 52.375, lng: 9.732 },
      zoom: 12,
      disableDefaultUI: true,
      zoomControl: true,
      styles: [
        { featureType: "poi", stylers: [{ visibility: "off" }] }
      ]
    });

    const infoWindow = new google.maps.InfoWindow();
    map.addListener("click", () => infoWindow.close());

    $.post(AAOTM.ajax_url, searchData, function (res) {

      if (!res.success || !res.data || !res.data.length) return;

      const bounds = new google.maps.LatLngBounds();

      res.data.forEach(function (item) {

        const marker = new google.maps.Marker({
          position: { lat: item.lat, lng: item.lng },
          map: map
        });

        marker.addListener("click", function () {

          // 🔑 ЧИТАЕМ ЦЕНУ ИЗ ТВОИХ КАРТОЧЕК BSBT
          const livePrice = getPriceFromDOMByUrl(item.url) || 'Check price';

          const content = `
            <div class="aaotm-clickable-card" onclick="window.open('${item.url}', '_blank');">
              <div class="aaotm-card-img-container">
                <div style="background-image:url('${item.img}')"></div>
              </div>
              <div class="aaotm-card-content">
                <h5>${item.title}</h5>
                <div class="aaotm-card-info">
                  <span>🛏️ ${item.rooms}</span>
                  <span>👤 Max: ${item.capacity}</span>
                </div>
                <div class="aaotm-card-price">${livePrice}</div>
              </div>
            </div>
          `;

          infoWindow.setContent(content);
          infoWindow.open(map, marker);
        });

        bounds.extend(marker.getPosition());
      });

      map.fitBounds(bounds);
      if (res.data.length === 1) map.setZoom(15);
    });
  }

  /* ======================================================
   * INIT
   * ====================================================== */

  $(window).on('load', function () {
    runMap();
  });

})(jQuery);
