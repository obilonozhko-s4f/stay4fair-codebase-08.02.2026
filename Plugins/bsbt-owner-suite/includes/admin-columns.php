<?php
if (!defined('ABSPATH')) exit;

/**
 * Колонки в списке брони:
 *  - Einkauf (Gesamt) — owner_price_per_night × Nächte (из RoomType)
 *  - Verkauf (Gesamt) — из HB/override
 *  - MwSt 7%          — ИЗЪЯТИЕ НДС из БРУТТО: gross * 7 / 107 (совпадает с PDF-инвойсом)
 * Внизу — сумма по видимым строкам.
 */

/** Утилиты (локальные, без внешних зависимостей) */
function bsbt__get_dates_raw($booking_id){
	$in  = get_post_meta($booking_id, 'mphb_check_in_date', true);
	$out = get_post_meta($booking_id, 'mphb_check_out_date', true);
	if (!$in)  $in  = get_post_meta($booking_id, '_mphb_check_in_date', true);
	if (!$out) $out = get_post_meta($booking_id, '_mphb_check_out_date', true);
	return [$in, $out];
}
function bsbt__nights($in, $out){
	$ti = strtotime($in); $to = strtotime($out);
	if (!$ti || !$to) return 0;
	return (int) round(max(0, $to - $ti) / DAY_IN_SECONDS);
}
function bsbt__guest_total($booking_id){
	$override = get_post_meta($booking_id, 'bsbt_override_guest_total', true);
	if ($override !== '' && $override !== null) return (float) $override;

	if (function_exists('mphb_get_booking')) {
		$bk = mphb_get_booking($booking_id);
		if ($bk && method_exists($bk, 'get_total_price')) return (float) $bk->get_total_price();
	}
	foreach (['mphb_booking_total_price','_mphb_booking_total_price','mphb_total_price','_mphb_total_price','mphb_price'] as $k){
		$v = get_post_meta($booking_id, $k, true);
		if ($v !== '' && $v !== null) return (float) $v;
	}
	return 0.0;
}
/** Сверх-надёжный поиск RoomType для брони */
function bsbt__discover_room_type($booking_id){
	$booking_id = (int) $booking_id;

	// прямые/кастомные мета
	foreach (['bsbt_room_type_id','mphb_room_type_id','_mphb_room_type_id'] as $k){
		$t = (int) get_post_meta($booking_id, $k, true);
		if ($t > 0) return $t;
	}

	// reserved_rooms: массив структур/ID, сериализованная строка, JSON
	$reserved = get_post_meta($booking_id, 'mphb_reserved_rooms', true);
	$try_extract = function($val){
		// массив структур [{room_type_id, room_id}]
		if (is_array($val) && isset($val[0]) && is_array($val[0])){
			if (isset($val[0]['room_type_id'])) {
				$t = (int) $val[0]['room_type_id'];
				if ($t > 0) return $t;
			}
			if (isset($val[0]['room_id'])) {
				$room_id = (int) $val[0]['room_id'];
				if ($room_id > 0){
					$t = (int) get_post_meta($room_id, 'mphb_room_type_id', true);
					if ($t > 0) return $t;
				}
			}
		}
		// массив ID reserved_room
		if (is_array($val) && !empty($val) && !isset($val[0]['room_type_id'])){
			$rr = (int) reset($val);
			if ($rr > 0){
				$t = (int) get_post_meta($rr, 'mphb_room_type_id', true);
				if ($t > 0) return $t;
				$room_id = (int) get_post_meta($rr, 'mphb_room_id', true);
				if ($room_id > 0){
					$t = (int) get_post_meta($room_id, 'mphb_room_type_id', true);
					if ($t > 0) return $t;
				}
			}
		}
		return 0;
	};
	if (!empty($reserved)) {
		$t = $try_extract($reserved);
		if ($t > 0) return $t;
		if (is_string($reserved)){
			$maybe = @maybe_unserialize($reserved);
			if ($maybe && $maybe !== $reserved){
				$t = $try_extract($maybe);
				if ($t > 0) return $t;
			}
			$j = json_decode($reserved, true);
			if (json_last_error() === JSON_ERROR_NONE && $j){
				$t = $try_extract($j);
				if ($t > 0) return $t;
			}
		}
	}

	// API MotoPress
	if (function_exists('mphb_get_booking')){
		$bk = mphb_get_booking($booking_id);
		if ($bk){
			foreach (['getRoomTypeId','get_room_type_id'] as $m){
				if (method_exists($bk,$m)){
					$t = (int) $bk->$m();
					if ($t > 0) return $t;
				}
			}
			if (method_exists($bk,'getReservedRooms')){
				$rooms = (array) $bk->getReservedRooms();
				if ($rooms){
					$first = reset($rooms);
					if (is_object($first)){
						foreach (['getRoomTypeId','get_room_type_id'] as $m){
							if (method_exists($first,$m)){ $t=(int)$first->$m(); if ($t>0) return $t; }
						}
						foreach (['getRoomId','get_room_id'] as $m){
							if (method_exists($first,$m)){
								$room_id = (int) $first->$m();
								if ($room_id > 0){
									$t = (int) get_post_meta($room_id, 'mphb_room_type_id', true);
									if ($t > 0) return $t;
								}
							}
						}
					}
				}
			}
			if (method_exists($bk,'getReservedRoomsIds')){
				$ids = (array) $bk->getReservedRoomsIds();
				if ($ids){
					$rr = (int) reset($ids);
					if ($rr > 0){
						$t = (int) get_post_meta($rr, 'mphb_room_type_id', true);
						if ($t > 0) return $t;
					}
				}
			}
		}
	}

	// WP_Query по reserved_room
	$q = new WP_Query([
		'post_type'      => 'mphb_reserved_room',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => [[ 'key'=>'mphb_booking_id', 'value'=>$booking_id ]]
	]);
	if ($q->have_posts()){
		$rr = (int) $q->posts[0];
		$t  = (int) get_post_meta($rr, 'mphb_room_type_id', true);
		if ($t > 0) return $t;
		$room_id = (int) get_post_meta($rr, 'mphb_room_id', true);
		if ($room_id > 0){
			$t = (int) get_post_meta($room_id, 'mphb_room_type_id', true);
			if ($t > 0) return $t;
		}
	}

	// room_id на самой брони
	foreach (['mphb_room_id','_mphb_room_id','mphb_room','_mphb_room'] as $k){
		$room_id = (int) get_post_meta($booking_id, $k, true);
		if ($room_id > 0){
			$t = (int) get_post_meta($room_id, 'mphb_room_type_id', true);
			if ($t > 0) return $t;
		}
	}
	return 0;
}

/** Добавление колонок */
function bsbt_add_fin_columns($cols){
	$new = [];
	foreach ($cols as $k=>$v){
		$new[$k] = $v;
		if ($k === 'title' || $k === 'mphb_booking_details') {
			$new['bsbt_purchase_total'] = __('Einkauf (Gesamt)', 'bsbt');
			$new['bsbt_guest_total']    = __('Verkauf (Gesamt)', 'bsbt');
			$new['bsbt_vat_7']          = __('MwSt 7%', 'bsbt');
		}
	}
	return $new;
}
add_filter('manage_mphb_booking_posts_columns', 'bsbt_add_fin_columns');

/** Рендер значений */
function bsbt_render_fin_columns($col, $post_id){
	if ($col === 'bsbt_purchase_total'){
		list($in,$out) = bsbt__get_dates_raw($post_id);
		$nights = bsbt__nights($in,$out);
		$typeid = bsbt__discover_room_type($post_id);

		$rate = 0.0;
		if ($typeid > 0) {
			$r = get_post_meta($typeid, 'owner_price_per_night', true);
			if ($r !== '' && $r !== null) $rate = (float) $r;
		}
		$purchase_total = round(max(0,$nights) * max(0,$rate), 2);

		// Кеш ставки в мету брони (на будущее для PDF/писем)
		update_post_meta($post_id, 'bsbt_owner_price_per_night', $rate);

		echo '<span class="bsbt-sum bsbt-purchase" data-val="'.esc_attr($purchase_total).'">'.
		     esc_html(number_format_i18n($purchase_total, 2)).' €</span>';
		return;
	}

	if ($col === 'bsbt_guest_total'){
		$guest_total = bsbt__guest_total($post_id);
		echo '<span class="bsbt-sum bsbt-guest" data-val="'.esc_attr($guest_total).'">'.
		     esc_html(number_format_i18n($guest_total, 2)).' €</span>';
		return;
	}

	if ($col === 'bsbt_vat_7'){
		$guest_total = bsbt__guest_total($post_id);
		// НДС 7% включён в цену: извлекаем долю налога из брутто (эквивалент gross - gross/1.07)
		$vat = ($guest_total > 0) ? round($guest_total * 7 / 107, 2) : 0.00;
		echo '<span class="bsbt-sum bsbt-vat" data-val="'.esc_attr($vat).'">'.
		     esc_html(number_format_i18n($vat, 2)).' €</span>';
		return;
	}
}
add_action('manage_mphb_booking_posts_custom_column','bsbt_render_fin_columns', 10, 2);

/** Сумма по видимым строкам внизу таблицы */
function bsbt_fin_totals_footer(){
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$screen || $screen->id !== 'edit-mphb_booking') return; ?>
	<script>
	(function(){
		const table = document.querySelector('table.wp-list-table.posts'); if(!table) return;
		let p=0,g=0,v=0;
		table.querySelectorAll('span.bsbt-purchase').forEach(el => p += parseFloat(el.dataset.val||0));
		table.querySelectorAll('span.bsbt-guest').forEach(el => g += parseFloat(el.dataset.val||0));
		table.querySelectorAll('span.bsbt-vat').forEach(el => v += parseFloat(el.dataset.val||0));

		const tfoot = table.querySelector('tfoot') || table.createTFoot();
		const tr = document.createElement('tr');
		tr.innerHTML = `
			<td colspan="3" style="text-align:right;font-weight:600">SUMMEN (sichtbar):</td>
			<td style="font-weight:600"><?php echo esc_html__('Einkauf (Gesamt):','bsbt'); ?> ${p.toLocaleString(undefined,{minimumFractionDigits:2})} €</td>
			<td style="font-weight:600"><?php echo esc_html__('Verkauf (Gesamt):','bsbt'); ?> ${g.toLocaleString(undefined,{minimumFractionDigits:2})} €</td>
			<td style="font-weight:600"><?php echo esc_html__('MwSt 7%:','bsbt'); ?> ${v.toLocaleString(undefined,{minimumFractionDigits:2})} €</td>
		`;
		tfoot.appendChild(tr);
	})();
	</script>
	<?php
}
add_action('admin_footer-edit.php','bsbt_fin_totals_footer');
