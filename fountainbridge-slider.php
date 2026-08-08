<?php
/**
 * Plugin Name: Fountainbridge Slider
 * Plugin URI:  https://fountainbridge.hu
 * Description: Könnyű, gyors slider plugin réteges (layer) animációkkal és egykattintásos Slider Revolution importtal. Shortcode: [fb_slider id="..."] vagy [fb_slider alias="..."]
 * Version:     260808
 * Author:      Fountainbridge
 * Author URI:  https://fountainbridge.hu
 * License:     GPLv2 or later
 * Text Domain: fb-slider
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FBSL_VER', '260808' );

final class FB_Slider_Plugin {

	private static $instance = null;
	private $assets_printed = false;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init',               [ $this, 'register_cpt' ] );
		add_action( 'admin_menu',         [ $this, 'admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
		add_shortcode( 'fb_slider',       [ $this, 'shortcode' ] );
		add_filter( 'post_gallery',       [ $this, 'intercept_gallery' ], 5, 3 );

		add_action( 'wp_ajax_fbsl_save',          [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_fbsl_delete',        [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_fbsl_rev_list',      [ $this, 'ajax_rev_list' ] );
		add_action( 'wp_ajax_fbsl_rev_import',    [ $this, 'ajax_rev_import' ] );
		add_action( 'wp_ajax_fbsl_rev7_import',   [ $this, 'ajax_rev7_import' ] );
		add_action( 'wp_ajax_fbsl_meta_list',     [ $this, 'ajax_meta_list' ] );
		add_action( 'wp_ajax_fbsl_meta_import',   [ $this, 'ajax_meta_import' ] );
		add_action( 'wp_ajax_fbsl_meta_debug',    [ $this, 'ajax_meta_debug' ] );
		add_action( 'wp_ajax_fbsl_master_list',   [ $this, 'ajax_master_list' ] );
		add_action( 'wp_ajax_fbsl_master_import', [ $this, 'ajax_master_import' ] );
		add_action( 'wp_ajax_fbsl_master_debug',  [ $this, 'ajax_master_debug' ] );
	}

	/* ---------------------------------------------------------------- CPT */

	public function register_cpt() {
		register_post_type( 'fb_slider', [
			'label'        => 'FB Sliders',
			'public'       => false,
			'show_ui'      => false,
			'supports'     => [ 'title' ],
		] );
	}

	/* ------------------------------------------------------- Default data */

	public static function default_data() {
		return [
			'settings' => [
				'width'      => 1240,
				'height'     => 600,
				'delay'      => 9000,
				'transition' => 'fade',      // fade | slide
				'autoplay'   => true,
				'loop'       => true,
				'arrows'     => true,
				'bullets'    => true,
				'pauseHover' => true,
				'bgColor'    => '#111111',
				'fullwidth'  => true,
				'thumbs'     => false,
				'thumbsAlign'=> 'bottom',
				'thumbW'     => 120,
				'thumbH'     => 80,
			],
			'slides' => [],
		];
	}

	public static function default_layer() {
		return [
			'type'    => 'text',      // text | button | image
			'content' => 'Új réteg',
			'img'     => '',
			'link'    => '',
			'newtab'  => false,
			'x' => 60, 'y' => 60, 'w' => 0, 'h' => 0,
			'fontSize'   => 40,
			'fontWeight' => 700,
			'color'      => '#ffffff',
			'bg'         => '',
			'pad'        => '',
			'radius'     => 0,
			'animIn'  => 'sft',
			'animOut' => 'fade',
			'thumbImg'=> '',
			'start'   => 500,
			'dur'     => 600,
			'end'     => 0,
		];
	}

	private function sanitize_data( $raw ) {
		$d   = self::default_data();
		$out = [ 'settings' => [], 'slides' => [] ];

		$s = isset( $raw['settings'] ) && is_array( $raw['settings'] ) ? $raw['settings'] : [];
		foreach ( $d['settings'] as $k => $def ) {
			$v = isset( $s[ $k ] ) ? $s[ $k ] : $def;
			if ( is_bool( $def ) )        $v = (bool) $v;
			elseif ( is_int( $def ) )     $v = (int) $v;
			elseif ( 'transition' === $k ) $v = in_array( $v, [ 'fade', 'slide' ], true ) ? $v : 'fade';
			elseif ( 'bgColor' === $k )   $v = sanitize_text_field( $v );
			$out['settings'][ $k ] = $v;
		}
		$out['settings']['width']  = max( 300, min( 4000, $out['settings']['width'] ) );
		$out['settings']['height'] = max( 100, min( 3000, $out['settings']['height'] ) );
		$out['settings']['thumbsAlign'] = in_array( $out['settings']['thumbsAlign'], [ 'bottom', 'top', 'left', 'right' ], true ) ? $out['settings']['thumbsAlign'] : 'bottom';
		$out['settings']['thumbW'] = max( 40, min( 400, (int) $out['settings']['thumbW'] ) );
		$out['settings']['thumbH'] = max( 30, min( 400, (int) $out['settings']['thumbH'] ) );

		$allowed_html = [
			'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [],
			'span' => [ 'style' => true, 'class' => true ],
		];

		$slides = isset( $raw['slides'] ) && is_array( $raw['slides'] ) ? $raw['slides'] : [];
		foreach ( array_slice( $slides, 0, 50 ) as $sl ) {
			if ( ! is_array( $sl ) ) continue;
			$slide = [
				'bg'      => esc_url_raw( isset( $sl['bg'] ) ? $sl['bg'] : '' ),
				'bgColor' => sanitize_text_field( isset( $sl['bgColor'] ) ? $sl['bgColor'] : '' ),
				'delay'   => isset( $sl['delay'] ) ? max( 0, (int) $sl['delay'] ) : 0,
				'layers'  => [],
			];
			$layers = isset( $sl['layers'] ) && is_array( $sl['layers'] ) ? $sl['layers'] : [];
			foreach ( array_slice( $layers, 0, 40 ) as $ly ) {
				if ( ! is_array( $ly ) ) continue;
				$def = self::default_layer();
				$L   = [];
				foreach ( $def as $k => $dv ) {
					$v = isset( $ly[ $k ] ) ? $ly[ $k ] : $dv;
					switch ( $k ) {
						case 'type':    $v = in_array( $v, [ 'text', 'button', 'image', 'url' ], true ) ? $v : 'text'; break;
						case 'content': $v = wp_kses( (string) $v, $allowed_html ); break;
						case 'img':
						case 'link':    $v = esc_url_raw( $v ); break;
						case 'newtab':  $v = (bool) $v; break;
						case 'color':
						case 'bg':
						case 'pad':     $v = sanitize_text_field( $v ); break;
						case 'animIn':
						case 'animOut': $v = preg_replace( '/[^a-z\-]/', '', (string) $v ); break;
						case 'thumbImg': $v = esc_url_raw( (string) $v ); break;
						default:        $v = (int) $v;
					}
					$L[ $k ] = $v;
				}
				$slide['layers'][] = $L;
			}
			$out['slides'][] = $slide;
		}
		return $out;
	}

	public function get_slider( $post_id ) {
		$json = get_post_meta( $post_id, '_fb_slider_data', true );
		$data = $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) $data = self::default_data();
		// merge defaults
		$data['settings'] = wp_parse_args(
			isset( $data['settings'] ) ? $data['settings'] : [],
			self::default_data()['settings']
		);
		if ( ! isset( $data['slides'] ) || ! is_array( $data['slides'] ) ) $data['slides'] = [];
		return $data;
	}

	/* --------------------------------------------------------- Admin menu */

	public function admin_menu() {
		add_menu_page(
			'Fountainbridge Slider', 'FB Slider', 'manage_options',
			'fb-slider', [ $this, 'render_admin' ], 'dashicons-images-alt2', 58
		);
		add_submenu_page(
			'fb-slider', 'Sliderek', 'Sliderek', 'manage_options',
			'fb-slider', [ $this, 'render_admin' ]
		);
		add_submenu_page(
			'fb-slider', 'Import', 'Import', 'manage_options',
			'fb-slider-import', [ $this, 'render_import' ]
		);
	}

	public function admin_assets( $hook ) {
		if ( ! in_array( $hook, [ 'toplevel_page_fb-slider', 'fb-slider_page_fb-slider-import' ], true ) ) return;
		wp_enqueue_media();
	}

	/* --------------------------------------------------------------- AJAX */

	private function check_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Nincs jogosultság.', 403 );
		check_ajax_referer( 'fbsl_nonce', 'nonce' );
	}

	public function ajax_save() {
		$this->check_ajax();
		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : 'Slider';
		$alias = isset( $_POST['alias'] ) ? sanitize_title( wp_unslash( $_POST['alias'] ) ) : '';
		$raw   = isset( $_POST['data'] ) ? json_decode( wp_unslash( $_POST['data'] ), true ) : null;
		if ( ! is_array( $raw ) ) wp_send_json_error( 'Hibás adat.' );

		$data = $this->sanitize_data( $raw );

		if ( $id && 'fb_slider' === get_post_type( $id ) ) {
			wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
		} else {
			$id = wp_insert_post( [ 'post_type' => 'fb_slider', 'post_status' => 'publish', 'post_title' => $title ] );
		}
		if ( ! $id || is_wp_error( $id ) ) wp_send_json_error( 'Mentés sikertelen.' );

		update_post_meta( $id, '_fb_slider_data', wp_json_encode( $data ) );
		update_post_meta( $id, '_fb_slider_alias', $alias ? $alias : 'slider-' . $id );
		wp_send_json_success( [ 'id' => $id, 'alias' => get_post_meta( $id, '_fb_slider_alias', true ) ] );
	}

	public function ajax_delete() {
		$this->check_ajax();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id && 'fb_slider' === get_post_type( $id ) ) {
			wp_delete_post( $id, true );
			wp_send_json_success();
		}
		wp_send_json_error( 'Nem található.' );
	}

	/* ---------------------------------------------- Slider Revolution import */

	private function rev_tables() {
		global $wpdb;
		$sliders = $wpdb->prefix . 'revslider_sliders';
		$slides  = $wpdb->prefix . 'revslider_slides';
		$found   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sliders ) );
		return $found ? [ $sliders, $slides ] : null;
	}

	public function ajax_rev_list() {
		$this->check_ajax();
		global $wpdb;
		$t = $this->rev_tables();
		if ( ! $t ) wp_send_json_success( [ 'found' => false, 'sliders' => [] ] );
		$rows = $wpdb->get_results( "SELECT id, title, alias FROM {$t[0]} ORDER BY id ASC", ARRAY_A );
		$list = [];
		foreach ( (array) $rows as $r ) {
			$cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t[1]} WHERE slider_id = %d", $r['id'] ) );
			$list[] = [ 'id' => (int) $r['id'], 'title' => $r['title'], 'alias' => $r['alias'], 'slides' => $cnt ];
		}
		wp_send_json_success( [ 'found' => true, 'sliders' => $list ] );
	}

	public function ajax_rev_import() {
		$this->check_ajax();
		global $wpdb;
		$rev_id = isset( $_POST['rev_id'] ) ? (int) $_POST['rev_id'] : 0;
		$t = $this->rev_tables();
		if ( ! $t || ! $rev_id ) wp_send_json_error( 'RevSlider táblák nem találhatók.' );
		$slider = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t[0]} WHERE id = %d", $rev_id ), ARRAY_A );
		if ( ! $slider ) wp_send_json_error( 'A slider nem található.' );

		$sp = json_decode( (string) $slider['params'], true );
		if ( ! is_array( $sp ) ) $sp = [];

		$data = self::default_data();
		$is6  = isset( $sp['size'] ) || isset( $sp['general'] ) || isset( $sp['layout'] );

		// ---- slider level settings
		$data['settings']['width']  = (int) $this->pick( $sp, [ 'width', 'size.width.d', 'size.width', 'layergrid.width' ], 1240 );
		$data['settings']['height'] = (int) $this->pick( $sp, [ 'height', 'size.height.d', 'size.height', 'layergrid.height' ], 600 );
		$data['settings']['delay']  = (int) $this->pick( $sp, [ 'delay', 'def.delay', 'general.slideshow.delay' ], 9000 );
		$loop = $this->pick( $sp, [ 'stop_slider', 'general.slideshow.stopSlider' ], 'off' );
		$data['settings']['loop']   = ( 'on' !== $loop && true !== $loop );
		$arrows = $this->pick( $sp, [ 'navigation_arrows', 'nav.arrows.set', 'navigation.arrows.enable' ], 'solo' );
		$data['settings']['arrows'] = ( 'none' !== $arrows && false !== $arrows && 'false' !== $arrows );
		$bullets = $this->pick( $sp, [ 'navigation_style', 'nav.bullets.set', 'navigation.bullets.enable' ], 'round' );
		$data['settings']['bullets'] = ( 'none' !== $bullets && false !== $bullets && 'false' !== $bullets );
		if ( $data['settings']['delay'] < 500 ) $data['settings']['delay'] = 9000;

		// ---- slides
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t[1]} WHERE slider_id = %d ORDER BY slide_order ASC", $rev_id
		), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$p = json_decode( (string) $row['params'], true );
			if ( ! is_array( $p ) ) $p = [];
			$ls = json_decode( (string) $row['layers'], true );
			if ( ! is_array( $ls ) ) $ls = [];

			// state (hidden/published)
			$state = $this->pick( $p, [ 'state', 'publish.state' ], 'published' );
			if ( 'unpublished' === $state ) continue;

			$bg = $this->pick( $p, [ 'image', 'bg.image', 'background.image.src' ], '' );
			$bg = $this->resolve_image( $bg, $p );

			$slide = [
				'bg'      => esc_url_raw( (string) $bg ),
				'bgColor' => sanitize_text_field( (string) $this->pick( $p, [ 'slide_bg_color', 'bg.color', 'background.color' ], '' ) ),
				'delay'   => (int) $this->pick( $p, [ 'delay', 'timeline.time', 'timeline.duration' ], 0 ),
				'layers'  => [],
			];
			if ( $slide['delay'] > 0 && $slide['delay'] < 500 ) $slide['delay'] = 0;

			// v6 layers come as object, v5 as array
			foreach ( (array) $ls as $ly ) {
				if ( ! is_array( $ly ) ) continue;
				$L = $this->import_layer( $ly, $is6 );
				if ( $L ) $slide['layers'][] = $L;
			}
			$data['slides'][] = $slide;
		}

		if ( empty( $data['slides'] ) ) wp_send_json_error( 'Nem találtam publikált slide-okat ebben a sliderben.' );

		$data = $this->sanitize_data( $data );

		$post_id = wp_insert_post( [
			'post_type'   => 'fb_slider',
			'post_status' => 'publish',
			'post_title'  => 'Import: ' . $slider['title'],
		] );
		if ( ! $post_id || is_wp_error( $post_id ) ) wp_send_json_error( 'Mentés sikertelen.' );

		$alias = sanitize_title( $slider['alias'] );
		update_post_meta( $post_id, '_fb_slider_data', wp_json_encode( $data ) );
		update_post_meta( $post_id, '_fb_slider_alias', $alias ? $alias : 'slider-' . $post_id );

		wp_send_json_success( [ 'id' => $post_id, 'title' => 'Import: ' . $slider['title'] ] );
	}

	/* --------------------------------------------- Slider Revolution 7 import (JSON/ZIP export) */

	public function ajax_rev7_import() {
		$this->check_ajax();
		if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['file']['error'] ) {
			wp_send_json_error( 'Nincs (érvényesen) feltöltött fájl.' );
		}
		$file = $_FILES['file'];
		if ( $file['size'] > 25 * 1024 * 1024 ) wp_send_json_error( 'A fájl túl nagy (max. 25 MB).' );

		$name     = strtolower( $file['name'] );
		$json_raw = null;

		if ( preg_match( '/\.zip$/', $name ) ) {
			if ( ! class_exists( 'ZipArchive' ) ) wp_send_json_error( 'A szerveren nincs ZIP támogatás.' );
			$zip = new ZipArchive();
			if ( true !== $zip->open( $file['tmp_name'] ) ) wp_send_json_error( 'A ZIP fájl nem olvasható.' );

			$json_entry = null;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( preg_match( '/\.json$/i', $stat['name'] ) ) { $json_entry = $stat['name']; break; }
			}
			if ( ! $json_entry ) { $zip->close(); wp_send_json_error( 'Nem találtam JSON fájlt a csomagban.' ); }
			$json_raw = $zip->getFromName( $json_entry );

			// Copy along bundled media (kept under a "media/" folder in the export) into the uploads dir,
			// preserving the relative path, so image references resolve without manual re-upload.
			$up = wp_get_upload_dir();
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$n = $zip->statIndex( $i )['name'];
				if ( ! preg_match( '#(?:^|/)media/(.+)$#i', $n, $m ) ) continue;
				$rel = ltrim( $m[1], '/' );
				if ( '' === $rel || '/' === substr( $n, -1 ) ) continue;
				$dest = trailingslashit( $up['basedir'] ) . $rel;
				if ( file_exists( $dest ) ) continue;
				$destdir = dirname( $dest );
				if ( ! is_dir( $destdir ) ) wp_mkdir_p( $destdir );
				$contents = $zip->getFromName( $n );
				if ( false !== $contents ) @file_put_contents( $dest, $contents );
			}
			$zip->close();
		} elseif ( preg_match( '/\.json$/', $name ) ) {
			$json_raw = file_get_contents( $file['tmp_name'] );
		} else {
			wp_send_json_error( 'Csak .json vagy .zip fájlt lehet feltölteni.' );
		}

		$sr = json_decode( (string) $json_raw, true );
		if ( ! is_array( $sr ) ) wp_send_json_error( 'A fájl nem érvényes JSON.' );

		$data = $this->sanitize_data( $this->convert_sr7( $sr ) );
		if ( empty( $data['slides'] ) ) wp_send_json_error( 'Nem találtam megjeleníthető slide-ot ebben az exportban.' );

		$title = isset( $sr['title'] ) && $sr['title'] ? sanitize_text_field( $sr['title'] ) : 'SR7 import';
		$post_id = wp_insert_post( [
			'post_type' => 'fb_slider', 'post_status' => 'publish', 'post_title' => 'Import: ' . $title,
		] );
		if ( ! $post_id || is_wp_error( $post_id ) ) wp_send_json_error( 'Mentés sikertelen.' );

		$alias = isset( $sr['alias'] ) ? sanitize_title( $sr['alias'] ) : '';
		update_post_meta( $post_id, '_fb_slider_data', wp_json_encode( $data ) );
		update_post_meta( $post_id, '_fb_slider_alias', $alias ? $alias : 'slider-' . $post_id );

		wp_send_json_success( [ 'id' => $post_id, 'title' => 'Import: ' . $title ] );
	}

	/* ---------------------------------------- [gallery masterslider="true"] intercept */

	public function intercept_gallery( $output, $attr, $instance ) {
		if ( empty( $attr['masterslider'] ) || 'true' !== strtolower( (string) $attr['masterslider'] ) ) return $output;
		if ( empty( $attr['ids'] ) ) return $output;

		$ids = array_filter( array_map( 'intval', explode( ',', $attr['ids'] ) ) );
		if ( ! $ids ) return $output;

		$s = self::default_data()['settings'];
		$s['arrows']      = true;
		$s['bullets']     = false;
		$s['autoplay']    = filter_var( isset( $attr['autoplay'] )    ? $attr['autoplay']    : 'false', FILTER_VALIDATE_BOOLEAN );
		$s['loop']        = filter_var( isset( $attr['loop'] )        ? $attr['loop']        : 'true',  FILTER_VALIDATE_BOOLEAN );
		$s['thumbs']      = filter_var( isset( $attr['thumbs'] )      ? $attr['thumbs']      : 'false', FILTER_VALIDATE_BOOLEAN );
		$s['thumbsAlign'] = isset( $attr['thumbs_align'] ) ? sanitize_text_field( $attr['thumbs_align'] ) : 'bottom';

		$slides = [];
		foreach ( $ids as $att_id ) {
			$url = wp_get_attachment_url( $att_id );
			if ( ! $url ) continue;
			$caption = wp_get_attachment_caption( $att_id );
			$layers  = [];
			if ( $caption && 'false' !== strtolower( (string) ( isset( $attr['caption'] ) ? $attr['caption'] : 'true' ) ) ) {
				$L = self::default_layer();
				$L['type']       = 'text';
				$L['content']    = esc_html( $caption );
				$L['x']          = 40;
				$L['y']          = (int) round( $s['height'] * 0.78 );
				$L['fontSize']   = 18;
				$L['fontWeight'] = 400;
				$L['color']      = '#ffffff';
				$L['bg']         = 'rgba(0,0,0,.5)';
				$L['pad']        = '8px 16px';
				$L['animIn']     = 'fade';
				$L['start']      = 300;
				$L['dur']        = 400;
				$layers[] = $L;
			}
			$thumb    = wp_get_attachment_image_url( $att_id, 'thumbnail' );
			$slides[] = [ 'bg' => esc_url_raw( $url ), 'bgColor' => '', 'delay' => 0, '_thumbImg' => $thumb ?: '', 'layers' => $layers ];
		}
		if ( ! $slides ) return $output;

		$data = [ 'settings' => $s, 'slides' => $slides ];
		return $this->render_slider_html( $data, 'gallery-' . md5( $attr['ids'] ) );
	}

	/* ---------------------------------------- Slider Revolution 7 helpers */

	private function convert_sr7( $sr ) {
		$data = self::default_data();
		$p    = isset( $sr['params'] ) && is_array( $sr['params'] ) ? $sr['params'] : [];

		$data['settings']['width']  = (int) $this->sr7_bp( $this->pick( $p, [ 'size.width' ] ), 1240 );
		$data['settings']['height'] = (int) $this->sr7_bp( $this->pick( $p, [ 'size.height' ] ), 600 );
		$data['settings']['delay']  = (int) $this->pick( $p, [ 'default.len' ], 9000 );
		if ( ! is_numeric( $data['settings']['delay'] ) || $data['settings']['delay'] < 500 ) $data['settings']['delay'] = 9000;
		$data['settings']['loop']    = true;
		$data['settings']['arrows']  = true;
		$data['settings']['bullets'] = true;

		$slides = isset( $sr['slides'] ) && is_array( $sr['slides'] ) ? $sr['slides'] : [];
		foreach ( $slides as $srSlide ) {
			if ( ! is_array( $srSlide ) ) continue;
			$sp    = isset( $srSlide['params'] ) && is_array( $srSlide['params'] ) ? $srSlide['params'] : [];
			$state = $this->pick( $sp, [ 'publish.state' ], 'published' );
			if ( 'unpublished' === $state ) continue;

			$slide = [ 'bg' => '', 'bgColor' => '', 'delay' => 0, 'layers' => [] ];
			$sdelay = $this->pick( $sp, [ 'slideshow.len' ], 0 );
			if ( is_numeric( $sdelay ) && (int) $sdelay >= 500 ) $slide['delay'] = (int) $sdelay;

			$layers = isset( $srSlide['layers'] ) && is_array( $srSlide['layers'] ) ? $srSlide['layers'] : [];
			uasort( $layers, function( $a, $b ) {
				$ao = is_array( $a ) && isset( $a['order'] ) ? (int) $a['order'] : 0;
				$bo = is_array( $b ) && isset( $b['order'] ) ? (int) $b['order'] : 0;
				return $ao <=> $bo;
			} );

			foreach ( $layers as $ly ) {
				if ( ! is_array( $ly ) ) continue;
				if ( true === $this->pick( $ly, [ 'runtime.idle.hidden' ], false ) ) continue;

				if ( 'slidebg' === $this->pick( $ly, [ 'subtype' ], '' ) ) {
					$src = $this->pick( $ly, [ 'bg.image.src' ], '' );
					if ( $src ) $slide['bg'] = esc_url_raw( $this->resolve_image( $src, [] ) );
					$col = $this->pick( $ly, [ 'bg.color.string' ], '' );
					if ( $col && 'transparent' !== $col ) $slide['bgColor'] = sanitize_text_field( $col );
					continue;
				}
				$L = $this->convert_sr7_layer( $ly );
				if ( $L ) $slide['layers'][] = $L;
			}
			if ( $slide['bg'] || $slide['bgColor'] || ! empty( $slide['layers'] ) ) $data['slides'][] = $slide;
		}
		return $data;
	}

	private function sr7_bp( $v, $default = 0 ) {
		if ( is_array( $v ) ) $v = isset( $v[0] ) ? $v[0] : $default;
		return is_numeric( $v ) ? $v : $default;
	}

	private function sr7_px( $v, $default = 0 ) {
		if ( is_array( $v ) ) $v = isset( $v[0] ) ? $v[0] : $default;
		if ( is_numeric( $v ) ) return (int) $v;
		if ( is_string( $v ) && preg_match( '/-?\d+/', $v, $m ) ) return (int) $m[0];
		return $default;
	}

	private function convert_sr7_layer( $ly ) {
		$href   = (string) $this->pick( $ly, [ 'href' ], '' );
		$target = (string) $this->pick( $ly, [ 'target' ], '_self' );
		$L = self::default_layer();
		$L['x'] = $this->sr7_px( $this->pick( $ly, [ 'pos.x' ] ), 40 );
		$L['y'] = $this->sr7_px( $this->pick( $ly, [ 'pos.y' ] ), 40 );
		$L['w'] = $this->sr7_px( $this->pick( $ly, [ 'size.w' ] ), 0 );
		$L['h'] = $this->sr7_px( $this->pick( $ly, [ 'size.h' ] ), 0 );
		if ( $href ) { $L['link'] = esc_url_raw( $href ); $L['newtab'] = ( '_blank' === $target ); }

		$img = (string) $this->pick( $ly, [ 'bg.image.src' ], '' );
		if ( $img ) {
			$L['type'] = 'image';
			$L['img']  = esc_url_raw( $this->resolve_image( $img, [] ) );
			return $L;
		}

		$text  = (string) $this->pick( $ly, [ 'content.text' ], '' );
		$text  = preg_replace( '/\[[^\]]*\]/', '', $text );
		$plain = trim( wp_strip_all_tags( (string) $text ) );

		if ( '' === $plain ) {
			if ( $href ) {
				$L['type'] = 'url'; $L['content'] = '';
				if ( ! $L['w'] ) $L['w'] = 200;
				if ( ! $L['h'] ) $L['h'] = 100;
				return $L;
			}
			return null;
		}

		$L['type']       = 'text';
		$L['content']    = wp_kses_post( $text );
		$L['fontSize']   = 24;
		$L['fontWeight'] = 400;
		$L['color']      = '#ffffff';
		return $L;
	}

	/* ---------------------------------------- RevSlider 5/6 layer helpers */

	private function pick( $arr, $paths, $default = null ) {
		foreach ( (array) $paths as $path ) {
			$cur = $arr;
			$ok  = true;
			foreach ( explode( '.', $path ) as $seg ) {
				if ( is_array( $cur ) && array_key_exists( $seg, $cur ) ) {
					$cur = $cur[ $seg ];
				} else { $ok = false; break; }
			}
			if ( $ok && null !== $cur && '' !== $cur ) return $cur;
		}
		return $default;
	}

	private function resolve_image( $img, $params = [] ) {
		if ( is_array( $img ) ) $img = isset( $img['src'] ) ? $img['src'] : '';
		$img = (string) $img;
		if ( '' === $img ) {
			$id = $this->pick( $params, [ 'image_id', 'bg.imageId', 'background.image.id' ], 0 );
			if ( $id ) { $u = wp_get_attachment_url( (int) $id ); if ( $u ) return $u; }
			return '';
		}
		if ( preg_match( '#^https?://#', $img ) ) return $img;
		$up = wp_get_upload_dir();
		return trailingslashit( $up['baseurl'] ) . ltrim( $img, '/' );
	}

	private function import_layer( $ly, $is6 ) {
		$type = strtolower( (string) $this->pick( $ly, [ 'type' ], 'text' ) );
		if ( in_array( $type, [ 'no_edit', 'group', 'row', 'column', 'audio', 'video' ], true ) ) return null;

		$map_type = 'text';
		if ( 'button' === $type ) $map_type = 'button';
		if ( 'image'  === $type ) $map_type = 'image';
		if ( 'shape'  === $type ) $map_type = 'text';

		$L = self::default_layer();
		$L['type'] = $map_type;

		$text = $this->pick( $ly, [ 'text' ], '' );
		if ( is_array( $text ) ) $text = '';
		$L['content'] = (string) $text;

		if ( 'image' === $map_type ) {
			$src = $this->pick( $ly, [ 'image_url', 'media.imageUrl', 'content.image', 'img' ], '' );
			$L['img'] = esc_url_raw( $this->resolve_image( $src, $ly ) );
			if ( '' === $L['img'] ) return null;
		} elseif ( '' === trim( wp_strip_all_tags( (string) $L['content'] ) ) ) {
			return null;
		}

		$x = $this->pick( $ly, [ 'left', 'pos.o.h.d', 'pos.o.h', 'position.horizontal.d' ], 60 );
		$y = $this->pick( $ly, [ 'top',  'pos.o.v.d', 'pos.o.v', 'position.vertical.d'   ], 60 );
		$L['x'] = $this->norm_pos( $x, 1240 );
		$L['y'] = $this->norm_pos( $y, 600 );

		$L['fontSize']   = (int) $this->pick( $ly, [ 'font_size', 'idle.fontSize.d', 'idle.fontSize', 'deformation.fontSize' ], 40 );
		if ( $L['fontSize'] < 8 ) $L['fontSize'] = 40;
		$fw = $this->pick( $ly, [ 'font_weight', 'idle.fontWeight', 'deformation.fontWeight' ], 700 );
		$L['fontWeight'] = (int) $fw >= 100 ? (int) $fw : 700;
		$L['color']      = (string) $this->pick( $ly, [ 'color', 'idle.color', 'deformation.color' ], '#ffffff' );

		$link = $this->pick( $ly, [ 'link', 'actions.link', 'layer_action.link_url' ], '' );
		if ( is_array( $link ) ) $link = '';
		$L['link'] = esc_url_raw( (string) $link );

		$L['start'] = (int) $this->pick( $ly, [ 'time', 'timeline.frames.frame_1.timeline.start', 'frames.frame_1.timeline.start' ], 500 );
		$L['dur']   = (int) $this->pick( $ly, [ 'speed', 'timeline.frames.frame_1.timeline.speed', 'frames.frame_1.timeline.speed' ], 600 );
		$L['end']   = (int) $this->pick( $ly, [ 'endtime' ], 0 );
		if ( $L['dur'] < 100 || $L['dur'] > 5000 ) $L['dur'] = 600;
		if ( $L['start'] < 0 || $L['start'] > 60000 ) $L['start'] = 500;

		$anim  = strtolower( (string) $this->pick( $ly, [ 'animation', 'timeline.frames.frame_1.animation.animation', 'frames.frame_1.animation' ], 'fade' ) );
		$eanim = strtolower( (string) $this->pick( $ly, [ 'endanimation', 'timeline.frames.frame_999.animation.animation' ], 'fade' ) );
		$L['animIn']  = $this->map_anim( $anim );
		$L['animOut'] = $this->map_anim( $eanim, true );

		return $L;
	}

	private function norm_pos( $v, $grid ) {
		if ( is_array( $v ) ) $v = reset( $v );
		if ( is_numeric( $v ) ) return (int) $v;
		$v = strtolower( (string) $v );
		if ( 'center' === $v || 'middle' === $v ) return (int) round( $grid / 2 - 100 );
		if ( 'left' === $v || 'top' === $v ) return 40;
		if ( 'right' === $v || 'bottom' === $v ) return (int) round( $grid * 0.7 );
		return (int) $v;
	}

	private function map_anim( $a, $out = false ) {
		$map = [
			'sft' => 'sft', 'sfb' => 'sfb', 'sfl' => 'sfl', 'sfr' => 'sfr',
			'lft' => 'lft', 'lfb' => 'lfb', 'lfl' => 'lfl', 'lfr' => 'lfr',
			'fade' => 'fade', 'zoomin' => 'zoomin', 'zoomout' => 'zoomout',
			'skewfromleft' => 'sfl', 'skewfromright' => 'sfr',
			'randomrotate' => 'zoomin',
		];
		foreach ( $map as $k => $v ) {
			if ( 0 === strpos( (string) $a, $k ) ) return $v;
		}
		return 'fade';
	}

	/* ---------------------------------------------------------------- Meta Slider debug */

	public function ajax_meta_debug() {
		$this->check_ajax();
		global $wpdb;
		$slider_id = isset( $_POST['slider_id'] ) ? (int) $_POST['slider_id'] : 0;
		if ( ! $slider_id ) wp_send_json_error( 'Hiányzó slider_id' );

		$out = [];

		// All meta keys on the slider post itself
		$out['slider_meta'] = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, LEFT(meta_value,200) as meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
			$slider_id
		), ARRAY_A );

		// All ml-slide posts with post_parent = slider_id (modern Meta Slider)
		$out['ml_slides_by_parent'] = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_type, post_parent, post_status, menu_order
			 FROM {$wpdb->posts}
			 WHERE post_type = 'ml-slide' AND post_parent = %d
			 ORDER BY menu_order ASC LIMIT 20",
			$slider_id
		), ARRAY_A );

		// Fallback: ml-slide posts linked via meta key
		$mlslides = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_parent, p.post_status,
			        pm.meta_key, pm.meta_value as sort_order
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE pm.meta_key = %s
			 LIMIT 20",
			"ml-slider_{$slider_id}"
		), ARRAY_A );
		$out['ml_slides_by_meta'] = $mlslides;

		// All ml-slide posts (any parent, to see what exists)
		$out['all_ml_slides_sample'] = $wpdb->get_results(
			"SELECT ID, post_type, post_parent, post_status, menu_order, post_title
			 FROM {$wpdb->posts}
			 WHERE post_type = 'ml-slide'
			 ORDER BY ID DESC LIMIT 10"
		, ARRAY_A );

		// Meta of the first non-trashed ml-slide matching this slider by title
		$first_slide_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'ml-slide' AND post_status != 'trash'
			   AND post_title LIKE %s
			 ORDER BY menu_order ASC LIMIT 1",
			$wpdb->esc_like( 'Slider ' . $slider_id . ' - ' ) . '%'
		) );
		if ( $first_slide_id ) {
			$out['first_slide_id'] = $first_slide_id;
			$out['first_slide_meta'] = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_key, LEFT(meta_value,300) as meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
				(int) $first_slide_id
			), ARRAY_A );
		}

		// For each found slide, list ALL its meta keys
		if ( $mlslides ) {
			foreach ( array_slice( $mlslides, 0, 3 ) as $sl ) {
				$out['slide_' . $sl['ID'] . '_meta'] = $wpdb->get_results( $wpdb->prepare(
					"SELECT meta_key, LEFT(meta_value,200) as meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
					(int) $sl['ID']
				), ARRAY_A );
			}
		}

		wp_send_json_success( $out );
	}

	/* ---------------------------------------------------------------- Meta Slider import */

	public function ajax_meta_list() {
		$this->check_ajax();
		$posts = get_posts( [
			'post_type'   => 'ml-slider',
			'post_status' => 'any',
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		] );
		if ( ! $posts ) { wp_send_json_success( [ 'found' => false, 'sliders' => [] ] ); return; }
		$list = [];
		foreach ( $posts as $p ) {
			$cnt = count( $this->meta_slider_get_slides( $p->ID ) );
			$list[] = [ 'id' => $p->ID, 'title' => $p->post_title, 'slides' => $cnt ];
		}
		wp_send_json_success( [ 'found' => true, 'sliders' => $list ] );
	}

	public function ajax_meta_import() {
		$this->check_ajax();
		$slider_id = isset( $_POST['slider_id'] ) ? (int) $_POST['slider_id'] : 0;
		if ( ! $slider_id || 'ml-slider' !== get_post_type( $slider_id ) ) wp_send_json_error( 'A slider nem található.' );

		$settings_raw = get_post_meta( $slider_id, 'ml-slider', true );
		$sp = is_array( $settings_raw ) ? $settings_raw : ( is_string( $settings_raw ) ? maybe_unserialize( $settings_raw ) : [] );
		if ( ! is_array( $sp ) ) $sp = [];

		$data = self::default_data();
		$data['settings']['width']     = isset( $sp['width'] )          ? (int) $sp['width']          : 1240;
		$data['settings']['height']    = isset( $sp['height'] )         ? (int) $sp['height']         : 600;
		$data['settings']['delay']     = isset( $sp['delay'] )          ? (int) $sp['delay']          : 9000;
		$data['settings']['autoplay']  = ! empty( $sp['autoPlay'] )     && 'false' !== $sp['autoPlay'] && false !== $sp['autoPlay'];
		$data['settings']['loop']      = empty( $sp['loop'] )           || 'false' !== $sp['loop'];
		$data['settings']['arrows']    = empty( $sp['showNavigation'] ) || in_array( $sp['showNavigation'], [ 'true', true, 'hover' ], true );
		$data['settings']['bullets']   = ! empty( $sp['showBullets'] )  && 'false' !== $sp['showBullets'] && false !== $sp['showBullets'];
		$data['settings']['pauseHover']= ! empty( $sp['pauseOnHover'] ) && 'false' !== $sp['pauseOnHover'] && false !== $sp['pauseOnHover'];
		if ( $data['settings']['delay'] < 500 ) $data['settings']['delay'] = 9000;

		$raw_slides = $this->meta_slider_get_slides( $slider_id );
		foreach ( $raw_slides as $rs ) {
			// Determine if this is a new-style ml-slide or legacy attachment
			$is_mlslide = ( 'ml-slide' === $rs->post_type );

			// Per-slide meta: stored as serialized array on key "ml-slider_{id}"
			$smeta_raw = get_post_meta( $rs->ID, "ml-slider_{$slider_id}", true );
			$smeta = is_array( $smeta_raw ) ? $smeta_raw
				: ( is_string( $smeta_raw ) ? maybe_unserialize( $smeta_raw ) : [] );
			if ( ! is_array( $smeta ) ) $smeta = [];

			// Background image
			if ( $is_mlslide ) {
				// ml-slide: the actual image attachment ID is in _metaslider_image_id or post_parent,
				// or the full-size URL is in _metaslider_thumb or the featured image.
				$att_id = (int) get_post_meta( $rs->ID, '_metaslider_image_id', true );
				if ( ! $att_id ) $att_id = (int) get_post_thumbnail_id( $rs->ID );
				if ( ! $att_id && $rs->post_parent ) $att_id = (int) $rs->post_parent;
				$bg = $att_id ? wp_get_attachment_url( $att_id ) : '';
				// Some versions store the full URL directly
				if ( ! $bg ) {
					$bg = (string) get_post_meta( $rs->ID, '_metaslider_thumb', true );
					if ( ! $bg ) $bg = (string) get_post_meta( $rs->ID, 'metaslider_image', true );
				}
			} else {
				// Legacy: the post itself IS the attachment
				$bg = wp_get_attachment_url( $rs->ID );
			}

			$slide = [
				'bg'      => $bg ? esc_url_raw( (string) $bg ) : '',
				'bgColor' => '',
				'delay'   => isset( $smeta['delay'] ) && (int) $smeta['delay'] >= 500 ? (int) $smeta['delay'] : 0,
				'layers'  => [],
			];

			// Caption / title → text layer
			// Meta Slider stores caption in slide meta 'caption', or falls back to attachment caption
			$caption = isset( $smeta['caption'] ) ? trim( (string) $smeta['caption'] ) : '';
			if ( '' === $caption ) {
				$caption = trim( $rs->post_excerpt ?: $rs->post_content );
			}
			// ml-slide can also store it as _metaslider_caption
			if ( '' === $caption && $is_mlslide ) {
				$caption = trim( (string) get_post_meta( $rs->ID, '_metaslider_caption', true ) );
			}
			if ( $caption ) {
				$L = self::default_layer();
				$L['type']       = 'text';
				$L['content']    = wp_kses_post( $caption );
				$L['x']          = 60;
				$L['y']          = (int) round( $data['settings']['height'] * 0.6 );
				$L['fontSize']   = 28;
				$L['fontWeight'] = 700;
				$L['color']      = '#ffffff';
				$L['animIn']     = 'sfb';
				$L['start']      = 600;
				$L['dur']        = 700;
				$slide['layers'][] = $L;
			}

			// Link
			$url    = isset( $smeta['url'] ) ? esc_url_raw( (string) $smeta['url'] ) : '';
			if ( ! $url && $is_mlslide ) {
				$url = esc_url_raw( (string) get_post_meta( $rs->ID, '_metaslider_url', true ) );
			}
			$target = ! empty( $smeta['newWindow'] ) ? '_blank' : '_self';
			if ( ! empty( $smeta['new_window'] ) ) $target = '_blank';

			if ( $url ) {
				if ( ! empty( $slide['layers'] ) ) {
					$slide['layers'][0]['link']   = $url;
					$slide['layers'][0]['newtab'] = ( '_blank' === $target );
				} else {
					$L = self::default_layer();
					$L['type']    = 'url';
					$L['content'] = '';
					$L['link']    = $url;
					$L['newtab']  = ( '_blank' === $target );
					$L['x'] = 0; $L['y'] = 0;
					$L['w'] = $data['settings']['width'];
					$L['h'] = $data['settings']['height'];
					$slide['layers'][] = $L;
				}
			}

			// Only add slide if it has a background image or at least one layer
			if ( $slide['bg'] || ! empty( $slide['layers'] ) ) {
				$data['slides'][] = $slide;
			}
		}

		if ( empty( $data['slides'] ) ) wp_send_json_error( 'Nem találtam slide-ot ehhez a sliderhez.' );
		$data = $this->sanitize_data( $data );

		$post_id = wp_insert_post( [
			'post_type' => 'fb_slider', 'post_status' => 'publish',
			'post_title' => 'Import: ' . get_the_title( $slider_id ),
		] );
		if ( ! $post_id || is_wp_error( $post_id ) ) wp_send_json_error( 'Mentés sikertelen.' );
		update_post_meta( $post_id, '_fb_slider_data', wp_json_encode( $data ) );
		update_post_meta( $post_id, '_fb_slider_alias', 'meta-slider-' . $slider_id );
		wp_send_json_success( [ 'id' => $post_id ] );
	}

	/** Return published attachment posts that belong to this Meta Slider. */
	private function meta_slider_get_slides( $slider_id ) {
		global $wpdb;

		// Meta Slider stores slides as 'ml-slide' posts.
		// The relationship is encoded in the post_title as "Slider {id} - image"
		// (post_parent is 0, no meta key link in this version).
		$title_prefix = 'Slider ' . (int) $slider_id . ' - ';
		$ids_by_title = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'ml-slide'
			   AND post_status != 'trash'
			   AND post_title LIKE %s
			 ORDER BY menu_order ASC",
			$wpdb->esc_like( $title_prefix ) . '%'
		) );
		if ( $ids_by_title ) {
			return get_posts( [
				'post_type'   => 'ml-slide',
				'post_status' => 'any',
				'include'     => array_map( 'intval', $ids_by_title ),
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
				'numberposts' => -1,
			] );
		}

		// Fallback A: post_parent = slider_id (some versions)
		$by_parent = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'ml-slide' AND post_parent = %d AND post_status != 'trash'
			 ORDER BY menu_order ASC",
			$slider_id
		) );
		if ( $by_parent ) {
			return get_posts( [
				'post_type'   => 'ml-slide',
				'post_status' => 'any',
				'include'     => array_map( 'intval', $by_parent ),
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
				'numberposts' => -1,
			] );
		}

		// Fallback B: meta key "ml-slider_{id}" on the slide post
		$ids_by_meta = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type IN ('ml-slide','attachment')
			   AND p.post_status != 'trash'
			   AND pm.meta_key = %s
			 ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC",
			"ml-slider_{$slider_id}"
		) );
		if ( $ids_by_meta ) {
			return get_posts( [
				'post_type'   => [ 'ml-slide', 'attachment' ],
				'post_status' => 'any',
				'include'     => array_map( 'intval', $ids_by_meta ),
				'orderby'     => 'post__in',
				'numberposts' => -1,
			] );
		}

		// Fallback C: taxonomy 'metaslider_slideshow' (very old versions)
		if ( taxonomy_exists( 'metaslider_slideshow' ) ) {
			$term = get_term_by( 'name', (string) $slider_id, 'metaslider_slideshow' );
			if ( $term ) {
				return get_posts( [
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'tax_query'   => [ [ 'taxonomy' => 'metaslider_slideshow', 'terms' => $term->term_id ] ],
					'orderby'     => 'menu_order',
					'order'       => 'ASC',
					'numberposts' => -1,
				] );
			}
		}

		return [];
	}


	/* ---------------------------------------------------------------- Master Slider import */

	private function master_tables() {
		global $wpdb;
		$t = $wpdb->prefix . 'masterslider_sliders';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ? $t : null;
	}

	/** Decode Master Slider params field (base64 JSON) into a usable array. */
	private function master_decode_params( $raw ) {
		if ( ! $raw ) return [];
		// Try base64 first (modern MS stores everything here)
		$decoded = base64_decode( $raw, true );
		if ( $decoded && isset( $decoded[0] ) && '{' === $decoded[0] ) {
			$d = json_decode( $decoded, true );
			if ( is_array( $d ) ) return $d;
		}
		// Try plain JSON
		$d = json_decode( $raw, true );
		if ( is_array( $d ) ) return $d;
		// Try unserialize
		$d = maybe_unserialize( $raw );
		return is_array( $d ) ? $d : [];
	}

	/** Count slides from params JSON (MSPanel.Slide) or slides table. */
	private function master_count_slides( $slider_id, $params_raw ) {
		$p = $this->master_decode_params( $params_raw );
		if ( ! empty( $p['MSPanel.Slide'] ) ) return count( $p['MSPanel.Slide'] );
		// Fallback: slides table
		global $wpdb;
		$slides_tbl = $wpdb->prefix . 'masterslider_slides';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $slides_tbl ) ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$slides_tbl} WHERE slider_id = %d", $slider_id ) );
		}
		return 0;
	}

	public function ajax_master_list() {
		$this->check_ajax();
		global $wpdb;
		$t = $this->master_tables();
		if ( ! $t ) { wp_send_json_success( [ 'found' => false, 'sliders' => [] ] ); return; }

		$rows = $wpdb->get_results( "SELECT id, title, params FROM {$t} ORDER BY id ASC", ARRAY_A );
		$list = [];
		foreach ( (array) $rows as $r ) {
			$cnt = $this->master_count_slides( (int) $r['id'], $r['params'] );
			$list[] = [ 'id' => (int) $r['id'], 'title' => $r['title'], 'slides' => $cnt ];
		}
		wp_send_json_success( [ 'found' => true, 'sliders' => $list ] );
	}

	public function ajax_master_import() {
		$this->check_ajax();
		global $wpdb;
		$t = $this->master_tables();
		if ( ! $t ) wp_send_json_error( 'Master Slider tábla nem található.' );

		$slider_id = isset( $_POST['slider_id'] ) ? (int) $_POST['slider_id'] : 0;
		$slider    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $slider_id ), ARRAY_A );
		if ( ! $slider ) wp_send_json_error( 'A slider nem található.' );

		$p = $this->master_decode_params( $slider['params'] );

		// Settings from MSPanel.Settings -> first entry
		$ms_settings = [];
		if ( ! empty( $p['MSPanel.Settings'] ) ) {
			$first = reset( $p['MSPanel.Settings'] );
			$ms_settings = is_string( $first ) ? json_decode( $first, true ) : $first;
			if ( ! is_array( $ms_settings ) ) $ms_settings = [];
		}
		// Fallback: old-style settings column
		if ( empty( $ms_settings ) && ! empty( $slider['settings'] ) ) {
			$ms_settings = maybe_unserialize( $slider['settings'] );
			if ( ! is_array( $ms_settings ) ) $ms_settings = [];
		}

		$data = self::default_data();
		$data['settings']['width']      = isset( $ms_settings['width'] )     ? (int) $ms_settings['width']     : 1240;
		$data['settings']['height']     = isset( $ms_settings['height'] )    ? (int) $ms_settings['height']    : 600;
		// duration in seconds -> ms
		$data['settings']['delay']      = isset( $ms_settings['duration'] )  ? (int) $ms_settings['duration'] * 1000
			: ( isset( $ms_settings['delay'] ) ? (int) $ms_settings['delay'] * 1000 : 7000 );
		$data['settings']['autoplay']   = ! empty( $ms_settings['autoplay'] );
		$data['settings']['loop']       = ! isset( $ms_settings['loop'] )    || (bool) $ms_settings['loop'];
		$data['settings']['arrows']     = true;
		$data['settings']['bullets']    = false;
		$data['settings']['pauseHover'] = ! empty( $ms_settings['overPause'] );
		$data['settings']['bgColor']    = isset( $ms_settings['bgColor'] )   ? sanitize_text_field( $ms_settings['bgColor'] ) : '#111111';
		if ( $data['settings']['delay'] < 1000 ) $data['settings']['delay'] = 7000;

		// Build Style lookup: styleModel id -> CSS properties
		$styles = [];
		if ( ! empty( $p['MSPanel.Style'] ) ) {
			foreach ( $p['MSPanel.Style'] as $sid => $sraw ) {
				$s = is_string( $sraw ) ? json_decode( $sraw, true ) : $sraw;
				if ( is_array( $s ) ) $styles[ (string) $sid ] = $s;
			}
		}
		// Also parse custom_styles CSS string as fallback (less reliable but useful)
		$custom_css_map = [];
		if ( ! empty( $slider['custom_styles'] ) ) {
			preg_match_all( '/\.(msp-cn-[^\s{]+)\s*\{([^}]+)\}/', $slider['custom_styles'], $matches, PREG_SET_ORDER );
			foreach ( $matches as $m ) {
				$custom_css_map[ $m[1] ] = $m[2];
			}
		}

		// Build Layer lookup: layer id -> layer data
		$layers_map = [];
		if ( ! empty( $p['MSPanel.Layer'] ) ) {
			foreach ( $p['MSPanel.Layer'] as $lid => $lraw ) {
				$l = is_string( $lraw ) ? json_decode( $lraw, true ) : $lraw;
				if ( is_array( $l ) ) $layers_map[ (string) $lid ] = $l;
			}
		}

		// Process slides
		$ms_slides = [];
		if ( ! empty( $p['MSPanel.Slide'] ) ) {
			foreach ( $p['MSPanel.Slide'] as $sid => $sraw ) {
				$s = is_string( $sraw ) ? json_decode( $sraw, true ) : $sraw;
				if ( is_array( $s ) ) $ms_slides[] = $s;
			}
			usort( $ms_slides, function( $a, $b ) {
				return (int) ( isset( $a['order'] ) ? $a['order'] : 0 ) - (int) ( isset( $b['order'] ) ? $b['order'] : 0 );
			} );
		}

		// Fallback: slides table
		if ( empty( $ms_slides ) ) {
			$slides_tbl = $wpdb->prefix . 'masterslider_slides';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $slides_tbl ) ) ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$slides_tbl} WHERE slider_id = %d ORDER BY `order` ASC", $slider_id
				), ARRAY_A );
				foreach ( (array) $rows as $row ) {
					$sj = $row['settings'] ? json_decode( $row['settings'], true ) : null;
					if ( is_array( $sj ) ) $ms_slides[] = $sj;
				}
			}
		}

		if ( empty( $ms_slides ) ) wp_send_json_error( 'Nem találtam slide-ot ebben a sliderben.' );

		$up = wp_get_upload_dir();
		foreach ( $ms_slides as $ms ) {
			if ( ! empty( $ms['ishide'] ) || ! empty( $ms['isHide'] ) ) continue;

			// Background image (relative path -> full URL)
			$bg_rel = isset( $ms['bg'] ) ? ltrim( (string) $ms['bg'], '/' ) : '';
			$thumb_rel = isset( $ms['bgThumb'] ) ? ltrim( (string) $ms['bgThumb'], '/' ) : '';
			$bg  = $bg_rel    ? trailingslashit( $up['baseurl'] ) . $bg_rel    : '';
			$thumb = $thumb_rel ? trailingslashit( $up['baseurl'] ) . $thumb_rel : '';

			$delay_s = isset( $ms['duration'] ) ? (float) $ms['duration'] : 0;
			$slide = [
				'bg'       => esc_url_raw( $bg ),
				'bgColor'  => '',
				'delay'    => $delay_s >= 1 ? (int) round( $delay_s * 1000 ) : 0,
				'_thumbImg'=> $thumb,
				'layers'   => [],
			];

			// Layers referenced by this slide
			$layer_ids = isset( $ms['layer_ids'] ) && is_array( $ms['layer_ids'] ) ? $ms['layer_ids'] : [];
			foreach ( $layer_ids as $lid ) {
				$lid = (string) $lid;
				if ( ! isset( $layers_map[ $lid ] ) ) continue;
				$L = $this->convert_master_panel_layer( $layers_map[ $lid ], $styles, $custom_css_map );
				if ( $L ) $slide['layers'][] = $L;
			}

			if ( $slide['bg'] || ! empty( $slide['layers'] ) ) {
				$data['slides'][] = $slide;
			}
		}

		if ( empty( $data['slides'] ) ) wp_send_json_error( 'Nem találtam megjeleníthető slide-ot.' );
		$data = $this->sanitize_data( $data );

		$post_id = wp_insert_post( [
			'post_type'   => 'fb_slider',
			'post_status' => 'publish',
			'post_title'  => 'Import: ' . sanitize_text_field( $slider['title'] ),
		] );
		if ( ! $post_id || is_wp_error( $post_id ) ) wp_send_json_error( 'Mentés sikertelen.' );
		update_post_meta( $post_id, '_fb_slider_data', wp_json_encode( $data ) );
		update_post_meta( $post_id, '_fb_slider_alias', 'master-slider-' . $slider_id );
		wp_send_json_success( [ 'id' => $post_id ] );
	}

	/** Convert a single MSPanel.Layer entry into our layer format. */
	private function convert_master_panel_layer( $ly, $styles, $custom_css_map ) {
		$type = strtolower( (string) ( isset( $ly['type'] ) ? $ly['type'] : 'text' ) );
		if ( in_array( $type, [ 'video', 'hotspot', 'group', 'slider' ], true ) ) return null;

		$L = self::default_layer();

		// Position: MSPanel uses offsetX/offsetY relative to slide center (origin: mc/mr/tl etc.)
		$L['x'] = isset( $ly['offsetX'] ) ? (int) round( (float) $ly['offsetX'] ) : 40;
		$L['y'] = isset( $ly['offsetY'] ) ? (int) round( (float) $ly['offsetY'] ) : 40;
		// Shift negative offsets to positive (center-relative to top-left)
		if ( $L['x'] < 0 ) $L['x'] = max( 0, $L['x'] + 565 );  // ~half of 1130
		if ( $L['y'] < 0 ) $L['y'] = max( 0, $L['y'] + 250 );  // ~half of 500

		// Style: resolved from styleModel id -> MSPanel.Style, then custom_styles CSS
		$style_id  = isset( $ly['styleModel'] ) ? (string) $ly['styleModel'] : '';
		$class_name = isset( $ly['className'] )  ? (string) $ly['className']  : '';
		$st = isset( $styles[ $style_id ] ) ? $styles[ $style_id ] : [];

		// fontSize / fontWeight / color from style
		if ( isset( $st['fontSize'] ) )    $L['fontSize']   = (int) $st['fontSize'];
		if ( isset( $st['fontWeight'] ) )  $L['fontWeight'] = (int) $st['fontWeight'];
		if ( isset( $st['color'] ) )       $L['color']      = sanitize_text_field( $st['color'] );
		if ( ! empty( $st['backgroundColor'] ) && 'rgba(0, 0, 0, 0)' !== $st['backgroundColor'] && 'transparent' !== $st['backgroundColor'] ) {
			$L['bg'] = sanitize_text_field( $st['backgroundColor'] );
		}
		if ( isset( $st['paddingTop'] ) || isset( $st['paddingRight'] ) ) {
			$pt = isset( $st['paddingTop'] )    ? (int) $st['paddingTop']    : 0;
			$pr = isset( $st['paddingRight'] )  ? (int) $st['paddingRight']  : 0;
			$pb = isset( $st['paddingBottom'] ) ? (int) $st['paddingBottom'] : 0;
			$pl = isset( $st['paddingLeft'] )   ? (int) $st['paddingLeft']   : 0;
			$L['pad'] = "{$pt}px {$pr}px {$pb}px {$pl}px";
		}

		// Fallback: parse from custom_styles CSS if style not in MSPanel.Style
		if ( $class_name && isset( $custom_css_map[ $class_name ] ) ) {
			$css = $custom_css_map[ $class_name ];
			if ( preg_match( '/font-size\s*:\s*(\d+)px/', $css, $m ) && $L['fontSize'] === 40 ) $L['fontSize'] = (int) $m[1];
			if ( preg_match( '/font-weight\s*:\s*(\d+)/', $css, $m ) && $L['fontWeight'] === 700 ) $L['fontWeight'] = (int) $m[1];
			if ( preg_match( '/color\s*:\s*(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\))/', $css, $m ) && $L['color'] === '#ffffff' ) $L['color'] = $m[1];
			if ( preg_match( '/background-color\s*:\s*(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\))/', $css, $m ) && '' === $L['bg'] ) $L['bg'] = $m[1];
		}

		// Link
		$link = isset( $ly['link'] ) ? esc_url_raw( (string) $ly['link'] ) : '';
		$L['link']   = $link;
		$L['newtab'] = false;

		// Animation: map showDelay to start, showDuration to dur
		$show_delay = isset( $ly['showDelay'] )    ? (int) round( (float) $ly['showDelay']    * 1000 ) : 500;
		$show_dur   = isset( $ly['showDuration'] ) ? (int) round( (float) $ly['showDuration'] * 1000 ) : 600;
		$L['start'] = $show_delay;
		$L['dur']   = $show_dur;

		// showTransform e.g. "perspective(2000px) translateY(-600px) rotate(-20deg) ..."
		$show_tf = isset( $ly['showTransform'] ) ? (string) $ly['showTransform'] : '';
		if ( preg_match( '/translateY\((-?\d+)/', $show_tf, $m ) ) {
			$L['animIn'] = (int) $m[1] < 0 ? 'sft' : 'sfb';
		} elseif ( preg_match( '/translateX\((-?\d+)/', $show_tf, $m ) ) {
			$L['animIn'] = (int) $m[1] < 0 ? 'sfl' : 'sfr';
		} elseif ( strpos( $show_tf, 'rotate' ) !== false ) {
			$L['animIn'] = 'zoomin';
		} else {
			$L['animIn'] = 'fade';
		}
		$L['animOut'] = isset( $ly['showFade'] ) && $ly['showFade'] ? 'fade' : 'fade';

		if ( 'image' === $type ) {
			$img = isset( $ly['img'] ) ? (string) $ly['img'] : '';
			if ( ! $img && isset( $ly['imgThumb'] ) ) $img = (string) $ly['imgThumb'];
			if ( ! $img ) return null;
			$L['type'] = 'image';
			$L['img']  = esc_url_raw( $this->resolve_image( ltrim( $img, '/' ), [] ) );
			return $L;
		}

		$content = isset( $ly['content'] ) ? (string) $ly['content'] : '';
		$plain   = trim( wp_strip_all_tags( $content ) );

		if ( '' === $plain ) {
			if ( $link ) {
				$L['type'] = 'url'; $L['content'] = '';
				if ( ! $L['w'] ) $L['w'] = 200;
				if ( ! $L['h'] ) $L['h'] = 100;
				return $L;
			}
			return null;
		}

		$L['type']    = ( 'button' === $type ) ? 'button' : 'text';
		$L['content'] = wp_kses_post( $content );
		return $L;
	}

	/** Old-style convert_master_layer kept for fallback (slides-table path). */
	private function convert_master_layer( $ly ) {
		return $this->convert_master_panel_layer( $ly, [], [] );
	}

	public function ajax_master_debug() {
		$this->check_ajax();
		global $wpdb;
		$slider_id = isset( $_POST['slider_id'] ) ? (int) $_POST['slider_id'] : 0;
		if ( ! $slider_id ) wp_send_json_error( 'Hiányzó slider_id' );
		$t = $this->master_tables();
		if ( ! $t ) wp_send_json_error( 'Nincs master slider tábla.' );

		$out = [];
		$slider = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $slider_id ), ARRAY_A );
		$out['slider_row'] = $slider ? array_merge( $slider, [ 'settings' => substr( (string) $slider['settings'], 0, 400 ) ] ) : null;

		$slides_tbl = $wpdb->prefix . 'masterslider_slides';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $slides_tbl ) ) ) {
			$out['slides_sample'] = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, slider_id, publish, `order`, LEFT(settings,500) as settings_preview FROM {$slides_tbl} WHERE slider_id = %d ORDER BY `order` ASC LIMIT 10",
				$slider_id
			), ARRAY_A );
			$out['total_slides'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$slides_tbl} WHERE slider_id = %d", $slider_id ) );
			$out['publish_1']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$slides_tbl} WHERE slider_id = %d AND publish = 1", $slider_id ) );
		} else {
			$out['slides_sample'] = [];
			$out['total_slides']  = 0;
			$out['publish_1']     = 0;
			$out['slides_note']   = 'masterslider_slides tábla nem létezik – adatok a params JSON-ban vannak';
		}
		wp_send_json_success( $out );
	}

	public function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['slider'] ) ? (int) $_GET['slider'] : 0;
		echo '<div class="wrap fbsl-wrap">';
		$this->admin_css_js();
		if ( 'edit' === $action ) {
			$this->render_editor( $id );
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	public function render_import() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$base       = admin_url( 'admin.php?page=fb-slider' );
		$import_url = admin_url( 'admin.php?page=fb-slider-import' );
		echo '<div class="wrap fbsl-wrap">';
		$this->admin_css_js();
		?>
		<div class="fbsl-head">
			<h1>Fountainbridge <span>Slider</span> <em>/ Import</em></h1>
		</div>

		<div class="fbsl-card" id="fbsl-rev-card">
			<h2>Slider Revolution (5/6) import</h2>
			<p class="fbsl-muted">Az adatbázisban talált RevSlider sliderek egy kattintással átemelhetők.</p>
			<div id="fbsl-rev-list"><em>Keresés…</em></div>
		</div>

		<div class="fbsl-card" id="fbsl-rev7-card">
			<h2>Slider Revolution 7 import (JSON / ZIP export)</h2>
			<p class="fbsl-muted">Exportáld a modult az SR7 adminból, és töltsd fel itt a <code>.json</code> vagy <code>.zip</code> fájlt.</p>
			<div class="fbsl-inline">
				<input type="file" id="fbsl-rev7-file" accept=".json,.zip">
				<button class="fbsl-btn fbsl-btn-primary" id="fbsl-rev7-go" type="button">Importálás</button>
			</div>
			<p class="fbsl-muted" id="fbsl-rev7-msg"></p>
		</div>

		<div class="fbsl-card" id="fbsl-meta-card">
			<h2>Meta Slider import</h2>
			<p class="fbsl-muted">Az adatbázisban talált Meta Slider sliderek listája.</p>
			<div id="fbsl-meta-list"><em>Keresés…</em></div>
			<p class="fbsl-muted" style="margin-top:12px">Ha 0 slide-ot mutat: <button class="fbsl-btn fbsl-mini" id="fbsl-meta-debug-btn" type="button">DB debug</button> <span id="fbsl-meta-debug-msg" style="font-size:12px;color:#50575e"></span></p>
			<pre id="fbsl-meta-debug-out" style="display:none;background:#1d2327;color:#a8c7fa;padding:12px;border-radius:6px;font-size:11px;max-height:300px;overflow:auto;margin-top:8px"></pre>
		</div>

		<div class="fbsl-card" id="fbsl-master-card">
			<h2>Master Slider import</h2>
			<p class="fbsl-muted">Az adatbázisban talált Master Slider sliderek listája.</p>
			<div id="fbsl-master-list"><em>Keresés…</em></div>
			<p class="fbsl-muted" style="margin-top:12px">Ha 0 slide-ot mutat: <button class="fbsl-btn fbsl-mini" id="fbsl-master-debug-btn" type="button">DB debug</button> <span id="fbsl-master-debug-msg" style="font-size:12px;color:#50575e"></span></p>
			<pre id="fbsl-master-debug-out" style="display:none;background:#1d2327;color:#a8c7fa;padding:12px;border-radius:6px;font-size:11px;max-height:300px;overflow:auto;margin-top:8px"></pre>
		</div>

		<script>
		(function(){
			var nonce = '<?php echo esc_js( wp_create_nonce( 'fbsl_nonce' ) ); ?>';
			var base  = '<?php echo esc_js( $base ); ?>';
			function post(action, fields){
				var fd = new FormData();
				fd.append('action', action); fd.append('nonce', nonce);
				for (var k in fields) fd.append(k, fields[k]);
				return fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){return r.json();});
			}
			function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
			function buildTable(listEl, action, rows, noMsg) {
				if (!rows || !rows.length) { listEl.innerHTML = '<p class="fbsl-muted">'+esc(noMsg)+'</p>'; return; }
				var html = '<table class="fbsl-table"><thead><tr><th>ID</th><th>Név</th><th>Slide-ok</th><th></th></tr></thead><tbody>';
				rows.forEach(function(s){
					html += '<tr><td>'+s.id+'</td><td><strong>'+esc(s.title)+'</strong></td><td>'+s.slides+'</td>'
					      + '<td class="fbsl-right"><button class="fbsl-btn fbsl-btn-primary fbsl-imp2" data-id="'+s.id+'" data-action="'+action+'" type="button">Import</button></td></tr>';
				});
				html += '</tbody></table>';
				listEl.innerHTML = html;
				listEl.querySelectorAll('.fbsl-imp2').forEach(function(b){
					b.addEventListener('click', function(){
						b.disabled=true; b.textContent='Importálás…';
						post(b.dataset.action, {slider_id: b.dataset.id}).then(function(res){
							if (res.success) { window.location = base+'&action=edit&slider='+res.data.id; }
							else { alert(res.data||'Hiba'); b.disabled=false; b.textContent='Import'; }
						});
					});
				});
			}
			// RevSlider 5/6
			var revEl = document.getElementById('fbsl-rev-list');
			post('fbsl_rev_list', {}).then(function(res){
				if (!res.success) { revEl.textContent='Hiba'; return; }
				if (!res.data.found) { revEl.innerHTML='<p class="fbsl-muted">Nincs RevSlider tábla.</p>'; return; }
				if (!res.data.sliders.length) { revEl.innerHTML='<p class="fbsl-muted">Nincs slider.</p>'; return; }
				var html = '<table class="fbsl-table"><thead><tr><th>ID</th><th>Név</th><th>Alias</th><th>Slide-ok</th><th></th></tr></thead><tbody>';
				res.data.sliders.forEach(function(s){
					html += '<tr><td>'+s.id+'</td><td><strong>'+esc(s.title)+'</strong></td><td><code>'+esc(s.alias)+'</code></td><td>'+s.slides+'</td>'
					      + '<td class="fbsl-right"><button class="fbsl-btn fbsl-btn-primary fbsl-rev-imp" data-id="'+s.id+'" type="button">Import</button></td></tr>';
				});
				html += '</tbody></table>';
				revEl.innerHTML = html;
				revEl.querySelectorAll('.fbsl-rev-imp').forEach(function(b){
					b.addEventListener('click', function(){
						b.disabled=true; b.textContent='Importálás…';
						post('fbsl_rev_import', {rev_id: b.dataset.id}).then(function(res){
							if (res.success) { window.location=base+'&action=edit&slider='+res.data.id; }
							else { alert(res.data||'Hiba'); b.disabled=false; b.textContent='Import'; }
						});
					});
				});
			});
			// SR7
			var r7btn=document.getElementById('fbsl-rev7-go'), r7file=document.getElementById('fbsl-rev7-file'), r7msg=document.getElementById('fbsl-rev7-msg');
			r7btn.addEventListener('click', function(){
				if (!r7file.files.length) { r7msg.textContent='Előbb válassz fájlt.'; return; }
				r7btn.disabled=true; r7btn.textContent='Importálás…'; r7msg.textContent='';
				var fd=new FormData(); fd.append('action','fbsl_rev7_import'); fd.append('nonce',nonce); fd.append('file',r7file.files[0]);
				fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();})
				.then(function(res){
					r7btn.disabled=false; r7btn.textContent='Importálás';
					if (res.success) window.location=base+'&action=edit&slider='+res.data.id;
					else r7msg.textContent=res.data||'Hiba';
				}).catch(function(){ r7btn.disabled=false; r7btn.textContent='Importálás'; r7msg.textContent='Feltöltési hiba.'; });
			});
			// Meta Slider
			var metaEl=document.getElementById('fbsl-meta-list'), _firstMetaId=0;
			post('fbsl_meta_list',{}).then(function(res){
				if (!res.success||!res.data.found) { metaEl.innerHTML='<p class="fbsl-muted">Nincs Meta Slider.</p>'; return; }
				if (res.data.sliders.length) _firstMetaId=res.data.sliders[0].id;
				buildTable(metaEl,'fbsl_meta_import',res.data.sliders,'Nincs Meta Slider.');
			});
			document.getElementById('fbsl-meta-debug-btn').addEventListener('click',function(){
				var msg=document.getElementById('fbsl-meta-debug-msg'), out=document.getElementById('fbsl-meta-debug-out');
				if (!_firstMetaId) { msg.textContent='Nincs slider ID.'; return; }
				msg.textContent='Lekérdezés…';
				post('fbsl_meta_debug',{slider_id:_firstMetaId}).then(function(res){ msg.textContent=''; out.style.display=''; out.textContent=JSON.stringify(res.data,null,2); });
			});
			// Master Slider
			var masterEl=document.getElementById('fbsl-master-list'), _firstMasterId=0;
			post('fbsl_master_list',{}).then(function(res){
				if (!res.success||!res.data.found) { masterEl.innerHTML='<p class="fbsl-muted">Nincs Master Slider tábla.</p>'; return; }
				if (res.data.sliders.length) _firstMasterId=res.data.sliders[0].id;
				buildTable(masterEl,'fbsl_master_import',res.data.sliders,'Nincs Master Slider.');
			});
			document.getElementById('fbsl-master-debug-btn').addEventListener('click',function(){
				var msg=document.getElementById('fbsl-master-debug-msg'), out=document.getElementById('fbsl-master-debug-out');
				if (!_firstMasterId) { msg.textContent='Nincs slider ID.'; return; }
				msg.textContent='Lekérdezés…';
				post('fbsl_master_debug',{slider_id:_firstMasterId}).then(function(res){ msg.textContent=''; out.style.display=''; out.textContent=JSON.stringify(res.data,null,2); });
			});
		})();
		</script>
		<?php
		echo '</div>';
	}

	private function render_list() {
		$sliders = get_posts( [ 'post_type' => 'fb_slider', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ] );
		$base = admin_url( 'admin.php?page=fb-slider' );
		?>
		<div class="fbsl-head">
			<h1>Fountainbridge <span>Slider</span></h1>
			<a class="fbsl-btn fbsl-btn-primary" href="<?php echo esc_url( $base . '&action=edit' ); ?>">+ Új slider</a>
		</div>

		<div class="fbsl-card">
			<h2>Sliderek</h2>
			<?php if ( ! $sliders ) : ?>
				<p class="fbsl-muted">Még nincs slider. Hozz létre egyet, vagy importálj az <a href="<?php echo esc_url( admin_url('admin.php?page=fb-slider-import') ); ?>">Import</a> almenüponton keresztül.</p>
			<?php else : ?>
			<table class="fbsl-table">
				<thead><tr><th>ID</th><th>Név</th><th>Shortcode</th><th>Slide-ok</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $sliders as $p ) :
					$data  = $this->get_slider( $p->ID );
					$alias = get_post_meta( $p->ID, '_fb_slider_alias', true );
				?>
					<tr data-id="<?php echo (int) $p->ID; ?>">
						<td><?php echo (int) $p->ID; ?></td>
						<td><strong><?php echo esc_html( $p->post_title ); ?></strong></td>
						<td><code>[fb_slider alias="<?php echo esc_attr( $alias ); ?>"]</code></td>
						<td><?php echo count( $data['slides'] ); ?></td>
						<td class="fbsl-right">
							<a class="fbsl-btn" href="<?php echo esc_url( $base . '&action=edit&slider=' . $p->ID ); ?>">Szerkesztés</a>
							<button class="fbsl-btn fbsl-btn-danger fbsl-del" type="button">Törlés</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<p class="fbsl-muted" style="margin-top:8px">Az importáláshoz használd az <a href="<?php echo esc_url( admin_url('admin.php?page=fb-slider-import') ); ?>">Import</a> almenüpontot.</p>
		<?php
	}

	private function render_editor( $id ) {
		$is_new = ! $id || 'fb_slider' !== get_post_type( $id );
		$data   = $is_new ? self::default_data() : $this->get_slider( $id );
		if ( $is_new && empty( $data['slides'] ) ) {
			$data['slides'][] = [ 'bg' => '', 'bgColor' => '', 'delay' => 0, 'layers' => [ self::default_layer() ] ];
		}
		$title = $is_new ? 'Új slider' : get_the_title( $id );
		$alias = $is_new ? '' : get_post_meta( $id, '_fb_slider_alias', true );
		$base  = admin_url( 'admin.php?page=fb-slider' );
		?>
		<div class="fbsl-head">
			<h1><a href="<?php echo esc_url( $base ); ?>">Fountainbridge <span>Slider</span></a> <em>/ szerkesztő</em></h1>
			<div>
				<span id="fbsl-savedmsg"></span>
				<button class="fbsl-btn fbsl-btn-primary" id="fbsl-save" type="button">Mentés</button>
			</div>
		</div>

		<div class="fbsl-card fbsl-row">
			<label>Név <input type="text" id="fbsl-title" value="<?php echo esc_attr( $title ); ?>"></label>
			<label>Alias <input type="text" id="fbsl-alias" value="<?php echo esc_attr( $alias ); ?>" placeholder="pl. fooldal"></label>
			<label>Shortcode <code id="fbsl-sc">[fb_slider alias="<?php echo esc_attr( $alias ?: '…' ); ?>"]</code></label>
		</div>

		<div class="fbsl-editor">
			<div class="fbsl-canvas-col">
				<div class="fbsl-slide-tabs" id="fbsl-slidetabs"></div>
				<div class="fbsl-canvas-outer">
					<div class="fbsl-canvas" id="fbsl-canvas"></div>
				</div>
				<div class="fbsl-canvas-tools">
					<button class="fbsl-btn" id="fbsl-setbg" type="button">Háttérkép…</button>
					<button class="fbsl-btn" id="fbsl-addtext" type="button">+ Szöveg</button>
					<button class="fbsl-btn" id="fbsl-addbtn" type="button">+ Gomb</button>
					<button class="fbsl-btn" id="fbsl-addimg" type="button">+ Kép réteg</button>
					<button class="fbsl-btn" id="fbsl-addurl" type="button">+ Link terület</button>
					<button class="fbsl-btn fbsl-btn-danger" id="fbsl-delslide" type="button">Slide törlése</button>
				</div>
				<p class="fbsl-muted">Tipp: a rétegeket egérrel húzhatod a vásznon. Kattints egy rétegre a szerkesztéshez.</p>
			</div>

			<div class="fbsl-panel-col">
				<div class="fbsl-card">
					<h3>Slider beállítások</h3>
					<div class="fbsl-grid2">
						<label>Rács szélesség (px)<input type="number" data-s="width"></label>
						<label>Rács magasság (px)<input type="number" data-s="height"></label>
						<label>Váltás ideje (ms)<input type="number" data-s="delay" step="500"></label>
						<label>Átmenet
							<select data-s="transition"><option value="fade">Áttűnés</option><option value="slide">Csúszás</option></select>
						</label>
						<label>Háttérszín<input type="text" data-s="bgColor" placeholder="#111111"></label>
						<label class="fbsl-check"><input type="checkbox" data-s="autoplay"> Automatikus lejátszás</label>
						<label class="fbsl-check"><input type="checkbox" data-s="loop"> Ismétlés</label>
						<label class="fbsl-check"><input type="checkbox" data-s="arrows"> Nyilak</label>
						<label class="fbsl-check"><input type="checkbox" data-s="bullets"> Pontok</label>
						<label class="fbsl-check"><input type="checkbox" data-s="pauseHover"> Megállás hoverre</label>
						<label class="fbsl-check"><input type="checkbox" data-s="thumbs"> Thumb sáv</label>
						<label>Thumb helye
							<select data-s="thumbsAlign"><option value="bottom">Alul</option><option value="top">Felül</option><option value="left">Balra</option><option value="right">Jobbra</option></select>
						</label>
						<label>Thumb szélesség (px)<input type="number" data-s="thumbW"></label>
						<label>Thumb magasság (px)<input type="number" data-s="thumbH"></label>
					</div>
				</div>

				<div class="fbsl-card">
					<h3>Slide beállítások</h3>
					<div class="fbsl-grid2">
						<label>Egyedi idő (ms, 0 = alap)<input type="number" id="fbsl-slidedelay" step="500"></label>
						<label>Slide háttérszín<input type="text" id="fbsl-slidebgcolor" placeholder="üres = nincs"></label>
						<label class="fbsl-full">Thumb kép (thumbnail sávhoz)
							<span class="fbsl-inline">
								<input type="text" id="fbsl-slidethumb" placeholder="üres = háttérkép">
								<button class="fbsl-btn fbsl-mini" id="fbsl-pickthumb" type="button">…</button>
							</span>
						</label>
					</div>
				</div>

				<div class="fbsl-card" id="fbsl-layercard" style="display:none">
					<h3>Kijelölt réteg <button class="fbsl-btn fbsl-btn-danger fbsl-mini" id="fbsl-dellayer" type="button">Törlés</button></h3>
					<div class="fbsl-grid2" id="fbsl-layerfields">
						<label class="fbsl-full">Tartalom<textarea data-l="content" rows="2"></textarea></label>
						<label class="fbsl-full" data-only="image">Kép URL
							<span class="fbsl-inline"><input type="text" data-l="img"><button class="fbsl-btn fbsl-mini" id="fbsl-pickimg" type="button">…</button></span>
						</label>
						<label>Link<input type="text" data-l="link"></label>
						<label class="fbsl-check"><input type="checkbox" data-l="newtab"> Új lapon</label>
						<label>X (px)<input type="number" data-l="x"></label>
						<label>Y (px)<input type="number" data-l="y"></label>
						<label>Szélesség (px, 0 = auto)<input type="number" data-l="w"></label>
						<label>Magasság (px, 0 = auto)<input type="number" data-l="h"></label>
						<label>Betűméret<input type="number" data-l="fontSize"></label>
						<label>Vastagság<select data-l="fontWeight"><option>300</option><option>400</option><option>500</option><option>600</option><option>700</option><option>800</option><option>900</option></select></label>
						<label>Szín<input type="text" data-l="color"></label>
						<label>Háttér<input type="text" data-l="bg" placeholder="pl. #e04a2f"></label>
						<label>Padding<input type="text" data-l="pad" placeholder="pl. 12px 28px"></label>
						<label>Lekerekítés (px)<input type="number" data-l="radius"></label>
						<label>Animáció be
							<select data-l="animIn">
								<option value="fade">Áttűnés</option>
								<option value="sft">Fentről (rövid)</option><option value="sfb">Lentről (rövid)</option>
								<option value="sfl">Balról (rövid)</option><option value="sfr">Jobbról (rövid)</option>
								<option value="lft">Fentről (hosszú)</option><option value="lfb">Lentről (hosszú)</option>
								<option value="lfl">Balról (hosszú)</option><option value="lfr">Jobbról (hosszú)</option>
								<option value="zoomin">Zoom be</option><option value="zoomout">Zoom ki</option>
							</select>
						</label>
						<label>Animáció ki
							<select data-l="animOut">
								<option value="fade">Áttűnés</option>
								<option value="sft">Fel</option><option value="sfb">Le</option>
								<option value="sfl">Balra</option><option value="sfr">Jobbra</option>
								<option value="zoomin">Zoom</option>
							</select>
						</label>
						<label>Indulás (ms)<input type="number" data-l="start" step="100"></label>
						<label>Időtartam (ms)<input type="number" data-l="dur" step="100"></label>
					</div>
				</div>
			</div>
		</div>

		<script id="fbsl-data" type="application/json"><?php echo wp_json_encode( $data ); ?></script>
		<script>
		(function(){
			var nonce = '<?php echo esc_js( wp_create_nonce( 'fbsl_nonce' ) ); ?>';
			var sliderId = <?php echo (int) ( $is_new ? 0 : $id ); ?>;
			var D = JSON.parse(document.getElementById('fbsl-data').textContent);
			var cur = 0, sel = -1, scale = 1;

			var canvas = document.getElementById('fbsl-canvas');
			var tabs   = document.getElementById('fbsl-slidetabs');
			var layerCard = document.getElementById('fbsl-layercard');

			function slide(){ return D.slides[cur]; }

			/* ---------- render ---------- */
			function renderTabs(){
				tabs.innerHTML = '';
				D.slides.forEach(function(s,i){
					var b = document.createElement('button');
					b.type='button'; b.className='fbsl-tab'+(i===cur?' on':''); b.textContent = (i+1);
					if (s.bg) b.style.backgroundImage = 'url('+s.bg+')';
					b.addEventListener('click', function(){ cur=i; sel=-1; renderAll(); });
					tabs.appendChild(b);
				});
				var add = document.createElement('button');
				add.type='button'; add.className='fbsl-tab fbsl-tab-add'; add.textContent='+';
				add.title='Új slide';
				add.addEventListener('click', function(){
					D.slides.push({bg:'',bgColor:'',delay:0,layers:[]});
					cur = D.slides.length-1; sel=-1; renderAll();
				});
				tabs.appendChild(add);
			}

			function renderCanvas(){
				var s = slide();
				var outer = canvas.parentElement;
				scale = Math.min(1, outer.clientWidth / D.settings.width);
				canvas.style.width  = D.settings.width + 'px';
				canvas.style.height = D.settings.height + 'px';
				canvas.style.transform = 'scale(' + scale + ')';
				outer.style.height = (D.settings.height * scale) + 'px';
				canvas.style.background = (s.bgColor || D.settings.bgColor || '#111') +
					(s.bg ? ' url(' + s.bg + ') center/cover no-repeat' : '');
				canvas.innerHTML = '';
				s.layers.forEach(function(L,i){
					var el = document.createElement('div');
					el.className = 'fbsl-cl fbsl-cl-' + L.type + (i===sel?' sel':'');
					el.style.left = L.x + 'px'; el.style.top = L.y + 'px';
					if (L.type === 'image') {
						var im = document.createElement('img'); im.src = L.img || ''; im.draggable = false;
						if (L.w) im.style.width = L.w + 'px';
						el.appendChild(im);
					} else if (L.type === 'url') {
						el.style.width  = (L.w || 300) + 'px';
						el.style.height = (L.h || 200) + 'px';
						el.title = L.link || '';
					} else {
						el.innerHTML = L.content || '&nbsp;';
						el.style.fontSize = L.fontSize + 'px';
						el.style.fontWeight = L.fontWeight;
						el.style.color = L.color;
						if (L.bg)  el.style.background = L.bg;
						if (L.pad) el.style.padding = L.pad;
						if (L.radius) el.style.borderRadius = L.radius + 'px';
					}
					dragify(el, i);
					if (i === sel && (L.type === 'url' || L.type === 'image')) {
						var rs = document.createElement('div');
						rs.className = 'fbsl-rs';
						resizify(rs, i, el);
						el.appendChild(rs);
					}
					canvas.appendChild(el);
				});
				document.getElementById('fbsl-slidedelay').value = s.delay || 0;
				document.getElementById('fbsl-slidebgcolor').value = s.bgColor || '';
				document.getElementById('fbsl-slidethumb').value = s._thumbImg || '';
			}

			function renderSettings(){
				document.querySelectorAll('[data-s]').forEach(function(inp){
					var k = inp.dataset.s;
					if (inp.type === 'checkbox') inp.checked = !!D.settings[k];
					else inp.value = D.settings[k];
				});
			}

			function renderLayerPanel(){
				if (sel < 0 || !slide().layers[sel]) { layerCard.style.display='none'; return; }
				layerCard.style.display='';
				var L = slide().layers[sel];
				document.querySelectorAll('[data-l]').forEach(function(inp){
					var k = inp.dataset.l;
					if (inp.type === 'checkbox') inp.checked = !!L[k];
					else inp.value = (L[k] == null ? '' : L[k]);
				});
				document.querySelectorAll('[data-only]').forEach(function(el){
					el.style.display = (el.dataset.only === L.type) ? '' : 'none';
				});
			}

			function renderAll(){ renderTabs(); renderCanvas(); renderSettings(); renderLayerPanel(); }

			/* ---------- drag ---------- */
			function dragify(el, i){
				el.addEventListener('mousedown', function(e){
					if (e.target.classList && e.target.classList.contains('fbsl-rs')) return;
					e.preventDefault();
					if (sel !== i){ sel = i; renderCanvas(); renderLayerPanel(); el = canvas.children[i]; }
					var L = slide().layers[i];
					var sx = e.clientX, sy = e.clientY, ox = L.x, oy = L.y;
					function mv(e2){
						L.x = Math.round(ox + (e2.clientX - sx) / scale);
						L.y = Math.round(oy + (e2.clientY - sy) / scale);
						el.style.left = L.x+'px'; el.style.top = L.y+'px';
					}
					function up(){
						document.removeEventListener('mousemove', mv);
						document.removeEventListener('mouseup', up);
						renderLayerPanel();
					}
					document.addEventListener('mousemove', mv);
					document.addEventListener('mouseup', up);
				});
			}

			function resizify(handle, i, el){
				handle.addEventListener('mousedown', function(e){
					e.preventDefault(); e.stopPropagation();
					var L = slide().layers[i];
					var sx = e.clientX, sy = e.clientY;
					var ow = L.w || (L.type==='url' ? 300 : (el.offsetWidth||300));
					var oh = L.h || (L.type==='url' ? 200 : 0);
					function mv(e2){
						L.w = Math.max(20, Math.round(ow + (e2.clientX - sx) / scale));
						if (L.type === 'url'){
							L.h = Math.max(20, Math.round(oh + (e2.clientY - sy) / scale));
							el.style.width = L.w+'px'; el.style.height = L.h+'px';
						} else {
							var im = el.querySelector('img');
							if (im) im.style.width = L.w+'px';
						}
					}
					function up(){
						document.removeEventListener('mousemove', mv);
						document.removeEventListener('mouseup', up);
						renderLayerPanel();
					}
					document.addEventListener('mousemove', mv);
					document.addEventListener('mouseup', up);
				});
			}

			document.addEventListener('keydown', function(e){
				if (sel < 0) return;
				var tag = (document.activeElement && document.activeElement.tagName) || '';
				if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
				var L = slide().layers[sel], step = e.shiftKey ? 10 : 1, moved = true;
				switch (e.key){
					case 'ArrowLeft':  L.x -= step; break;
					case 'ArrowRight': L.x += step; break;
					case 'ArrowUp':    L.y -= step; break;
					case 'ArrowDown':  L.y += step; break;
					default: moved = false;
				}
				if (moved){ e.preventDefault(); renderCanvas(); renderLayerPanel(); }
			});
			canvas.addEventListener('mousedown', function(e){
				if (e.target === canvas) { sel = -1; renderCanvas(); renderLayerPanel(); }
			});

			/* ---------- inputs ---------- */
			document.querySelectorAll('[data-s]').forEach(function(inp){
				inp.addEventListener('input', function(){
					var k = inp.dataset.s;
					D.settings[k] = (inp.type==='checkbox') ? inp.checked :
						(inp.type==='number' ? parseInt(inp.value||0,10) : inp.value);
					renderCanvas();
				});
			});
			document.querySelectorAll('[data-l]').forEach(function(inp){
				inp.addEventListener('input', function(){
					if (sel<0) return;
					var L = slide().layers[sel], k = inp.dataset.l;
					L[k] = (inp.type==='checkbox') ? inp.checked :
						(inp.type==='number' ? parseInt(inp.value||0,10) : inp.value);
					renderCanvas();
				});
			});
			document.getElementById('fbsl-slidedelay').addEventListener('input', function(){ slide().delay = parseInt(this.value||0,10); });
			document.getElementById('fbsl-slidebgcolor').addEventListener('input', function(){ slide().bgColor = this.value; renderCanvas(); });
			document.getElementById('fbsl-slidethumb').addEventListener('input', function(){ slide()._thumbImg = this.value; });
			document.getElementById('fbsl-pickthumb').addEventListener('click', function(){
				pickMedia(function(url){ slide()._thumbImg = url; document.getElementById('fbsl-slidethumb').value = url; renderTabs(); });
			});

			/* ---------- toolbar ---------- */
			function addLayer(type){
				var L = <?php echo wp_json_encode( self::default_layer() ); ?>;
				L.type = type;
				if (type==='button'){ L.content='Gomb'; L.fontSize=18; L.bg='#e04a2f'; L.pad='12px 30px'; L.radius=4; }
				if (type==='url'){ L.content=''; L.w=300; L.h=200; }
				if (type==='image'){ L.content=''; pickMedia(function(url){ L.img=url; slide().layers.push(L); sel=slide().layers.length-1; renderCanvas(); renderLayerPanel(); }); return; }
				slide().layers.push(L); sel = slide().layers.length-1;
				renderCanvas(); renderLayerPanel();
			}
			document.getElementById('fbsl-addtext').addEventListener('click', function(){ addLayer('text'); });
			document.getElementById('fbsl-addbtn').addEventListener('click', function(){ addLayer('button'); });
			document.getElementById('fbsl-addimg').addEventListener('click', function(){ addLayer('image'); });
			document.getElementById('fbsl-addurl').addEventListener('click', function(){ addLayer('url'); });
			document.getElementById('fbsl-dellayer').addEventListener('click', function(){
				if (sel<0) return; slide().layers.splice(sel,1); sel=-1; renderCanvas(); renderLayerPanel();
			});
			document.getElementById('fbsl-delslide').addEventListener('click', function(){
				if (D.slides.length<=1) { alert('Legalább egy slide kell.'); return; }
				if (!confirm('Törlöd ezt a slide-ot?')) return;
				D.slides.splice(cur,1); cur = Math.max(0, cur-1); sel=-1; renderAll();
			});
			document.getElementById('fbsl-setbg').addEventListener('click', function(){
				pickMedia(function(url){ slide().bg = url; renderAll(); });
			});
			document.getElementById('fbsl-pickimg').addEventListener('click', function(){
				pickMedia(function(url){ if (sel>=0){ slide().layers[sel].img = url; renderCanvas(); renderLayerPanel(); } });
			});

			function pickMedia(cb){
				var f = wp.media({ title:'Kép kiválasztása', multiple:false, library:{type:'image'} });
				f.on('select', function(){ cb(f.state().get('selection').first().toJSON().url); });
				f.open();
			}

			/* ---------- save ---------- */
			document.getElementById('fbsl-save').addEventListener('click', function(){
				var btn = this; btn.disabled = true;
				var fd = new FormData();
				fd.append('action','fbsl_save'); fd.append('nonce', nonce);
				fd.append('id', sliderId);
				fd.append('title', document.getElementById('fbsl-title').value || 'Slider');
				fd.append('alias', document.getElementById('fbsl-alias').value || '');
				fd.append('data', JSON.stringify(D));
				fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:fd})
					.then(function(r){return r.json();})
					.then(function(res){
						btn.disabled = false;
						if (res.success){
							sliderId = res.data.id;
							document.getElementById('fbsl-alias').value = res.data.alias;
							document.getElementById('fbsl-sc').textContent = '[fb_slider alias="'+res.data.alias+'"]';
							var m = document.getElementById('fbsl-savedmsg');
							m.textContent = 'Mentve ✓'; setTimeout(function(){ m.textContent=''; }, 2500);
							history.replaceState(null,'', '<?php echo esc_js( $base ); ?>&action=edit&slider='+sliderId);
						} else alert(res.data || 'Hiba a mentésnél');
					});
			});

			window.addEventListener('resize', renderCanvas);
			renderAll();
		})();
		</script>
		<?php
	}

	private function admin_css_js() {
		?>
		<style>
		.fbsl-wrap{--fb-ink:#1d2327;--fb-accent:#e04a2f;--fb-line:#dcdcde;max-width:1500px}
		.fbsl-head{display:flex;justify-content:space-between;align-items:center;margin:8px 0 18px}
		.fbsl-head h1{font-size:24px;font-weight:800;letter-spacing:-.02em;margin:0}
		.fbsl-head h1 a{text-decoration:none;color:inherit}
		.fbsl-head h1 span{color:var(--fb-accent)}
		.fbsl-head h1 em{font-style:normal;font-weight:400;color:#787c82;font-size:16px}
		.fbsl-card{background:#fff;border:1px solid var(--fb-line);border-radius:10px;padding:18px 20px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
		.fbsl-card h2,.fbsl-card h3{margin-top:0}
		.fbsl-muted{color:#787c82}
		.fbsl-btn{display:inline-block;border:1px solid var(--fb-line);background:#fff;border-radius:6px;padding:7px 14px;cursor:pointer;text-decoration:none;color:var(--fb-ink);font-size:13px;line-height:1.4}
		.fbsl-btn:hover{border-color:#999}
		.fbsl-btn-primary{background:var(--fb-accent);border-color:var(--fb-accent);color:#fff;font-weight:600}
		.fbsl-btn-primary:hover{background:#c53f27;color:#fff}
		.fbsl-btn-danger{color:#b32d2e;border-color:#e5b3b3}
		.fbsl-mini{padding:2px 8px;font-size:12px}
		.fbsl-table{width:100%;border-collapse:collapse}
		.fbsl-table th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#787c82;padding:8px 10px;border-bottom:1px solid var(--fb-line)}
		.fbsl-table td{padding:10px;border-bottom:1px solid #f0f0f1}
		.fbsl-right{text-align:right;white-space:nowrap}
		.fbsl-row{display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end}
		.fbsl-row label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#50575e}
		.fbsl-row input{min-width:220px}
		.fbsl-editor{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:16px;align-items:start}
		@media (max-width:1100px){.fbsl-editor{grid-template-columns:1fr}}
		.fbsl-canvas-outer{overflow:hidden;border:1px solid var(--fb-line);border-radius:10px;background:#26292c}
		.fbsl-canvas{position:relative;transform-origin:0 0;user-select:none}
		.fbsl-cl{position:absolute;cursor:move;line-height:1.2;white-space:pre-wrap;outline:1px dashed transparent}
		.fbsl-cl:hover{outline-color:rgba(255,255,255,.5)}
		.fbsl-cl.sel{outline:2px solid var(--fb-accent)}
		.fbsl-cl img{display:block;max-width:none}
		.fbsl-cl-url{outline:2px dashed #4aa3e0!important;background:rgba(74,163,224,.15)}
		.fbsl-cl-url::after{content:'link terület';position:absolute;left:4px;top:2px;font-size:11px;color:#cfe8fa;font-weight:600}
		.fbsl-rs{position:absolute;right:-8px;bottom:-8px;width:16px;height:16px;background:var(--fb-accent);border:2px solid #fff;border-radius:3px;cursor:nwse-resize;box-shadow:0 1px 3px rgba(0,0,0,.4)}
		.fbsl-canvas-tools{display:flex;gap:8px;margin:12px 0;flex-wrap:wrap}
		.fbsl-slide-tabs{display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap}
		.fbsl-tab{width:56px;height:36px;border:2px solid var(--fb-line);border-radius:6px;background:#3a3d40 center/cover;color:#fff;font-weight:700;cursor:pointer;text-shadow:0 1px 2px rgba(0,0,0,.7)}
		.fbsl-tab.on{border-color:var(--fb-accent)}
		.fbsl-tab-add{background:#fff;color:var(--fb-ink);text-shadow:none}
		.fbsl-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px 12px}
		.fbsl-grid2 label{display:flex;flex-direction:column;gap:3px;font-size:12px;font-weight:600;color:#50575e}
		.fbsl-grid2 .fbsl-check{flex-direction:row;align-items:center;font-weight:400}
		.fbsl-grid2 .fbsl-full{grid-column:1/-1}
		.fbsl-grid2 input[type=text],.fbsl-grid2 input[type=number],.fbsl-grid2 select,.fbsl-grid2 textarea{width:100%}
		.fbsl-inline{display:flex;gap:6px}
		#fbsl-savedmsg{color:#00844a;font-weight:600;margin-right:10px}
		</style>
		<?php
	}

	/* ---------------------------------------------------------- Frontend */

	public function shortcode( $atts ) {
		$atts = shortcode_atts( [ 'id' => 0, 'alias' => '' ], $atts, 'fb_slider' );
		$id   = (int) $atts['id'];

		if ( ! $id && $atts['alias'] ) {
			$q = get_posts( [
				'post_type'   => 'fb_slider',
				'numberposts' => 1,
				'meta_key'    => '_fb_slider_alias',
				'meta_value'  => sanitize_title( $atts['alias'] ),
				'fields'      => 'ids',
			] );
			if ( $q ) $id = (int) $q[0];
		}
		if ( ! $id || 'fb_slider' !== get_post_type( $id ) ) return '';

		$data = $this->get_slider( $id );
		if ( empty( $data['slides'] ) ) return '';

		$s   = $data['settings'];
		$uid = 'fbsl-' . $id;

		$allowed_html = [
			'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [],
			'span' => [ 'style' => true, 'class' => true ],
		];

		ob_start();
		$this->print_assets();

		// LCP optimisation: preload the first slide's background image in <head>
		$first_bg = isset( $data['slides'][0]['bg'] ) ? $data['slides'][0]['bg'] : '';
		if ( $first_bg ) {
			add_action( 'wp_head', function() use ( $first_bg ) {
				printf(
					'<link rel="preload" as="image" href="%s" fetchpriority="high">' . "\n",
					esc_url( $first_bg )
				);
			}, 1 );
		}
		?>
		<div class="fbsl" id="<?php echo esc_attr( $uid ); ?>"
			data-fbsl="<?php echo esc_attr( wp_json_encode( [
				'w' => $s['width'], 'h' => $s['height'], 'delay' => $s['delay'],
				'tr' => $s['transition'], 'auto' => $s['autoplay'], 'loop' => $s['loop'],
				'hover' => $s['pauseHover'],
			] ) ); ?>"
			style="background: <?php echo esc_attr( $s['bgColor'] ); ?>;">
			<div class="fbsl-stage">
			<?php foreach ( $data['slides'] as $si => $slide ) :
				$is_first_slide = ( 0 === $si );
			?>
				<div class="fbsl-slide<?php echo $is_first_slide ? ' fbsl-on' : ''; ?>"
					data-delay="<?php echo (int) $slide['delay']; ?>"
					<?php if ( $slide['bg'] && ! $is_first_slide ) : ?>data-bg="<?php echo esc_url( $slide['bg'] ); ?>"<?php endif; ?>
					style="<?php echo $slide['bgColor'] ? 'background-color:' . esc_attr( $slide['bgColor'] ) . ';' : ''; ?>">
					<?php
					// First slide: use a real <img> as background for LCP discoverability + fetchpriority
					if ( $is_first_slide && $slide['bg'] ) : ?>
					<img class="fbsl-bgimg"
						src="<?php echo esc_url( $slide['bg'] ); ?>"
						alt=""
						fetchpriority="high"
						decoding="async"
						aria-hidden="true">
					<?php endif; ?>
					<div class="fbsl-grid">
					<?php foreach ( $slide['layers'] as $L ) :
						if ( 'url' === $L['type'] && ! $L['link'] ) continue;
						$style = sprintf( 'left:%dpx;top:%dpx;', (int) $L['x'], (int) $L['y'] );
						if ( 'url' === $L['type'] ) {
							$style .= sprintf( 'width:%dpx;height:%dpx;',
								(int) ( $L['w'] ?: 300 ), (int) ( $L['h'] ?: 200 ) );
						} elseif ( 'image' !== $L['type'] ) {
							$style .= sprintf( 'font-size:%dpx;font-weight:%d;color:%s;',
								(int) $L['fontSize'], (int) $L['fontWeight'], esc_attr( $L['color'] ) );
							if ( $L['bg'] )     $style .= 'background:' . esc_attr( $L['bg'] ) . ';';
							if ( $L['pad'] )    $style .= 'padding:' . esc_attr( $L['pad'] ) . ';';
							if ( $L['radius'] ) $style .= 'border-radius:' . (int) $L['radius'] . 'px;';
						} elseif ( $L['w'] ) {
							$style .= 'width:' . (int) $L['w'] . 'px;';
						}
						$anim = sprintf( ' data-in="%s" data-out="%s" data-start="%d" data-dur="%d" data-end="%d"',
							esc_attr( $L['animIn'] ), esc_attr( $L['animOut'] ),
							(int) $L['start'], (int) $L['dur'], (int) $L['end'] );

						// First slide layers: no lazy loading, fetchpriority=high on images
						if ( 'image' === $L['type'] ) {
							$img_attrs = $is_first_slide
								? 'fetchpriority="high" decoding="async"'
								: 'loading="lazy" decoding="async"';
							$inner = '<img src="' . esc_url( $L['img'] ) . '" alt="" ' . $img_attrs . '>';
						} elseif ( 'url' === $L['type'] ) {
							$inner = '';
						} else {
							$inner = wp_kses( $L['content'], $allowed_html );
						}

						if ( $L['link'] ) {
							$tgt = $L['newtab'] ? ' target="_blank" rel="noopener"' : '';
							printf( '<a class="fbsl-layer fbsl-l-%s" href="%s"%s style="%s"%s>%s</a>',
								esc_attr( $L['type'] ), esc_url( $L['link'] ), $tgt, esc_attr( $style ), $anim, $inner );
						} else {
							printf( '<div class="fbsl-layer fbsl-l-%s" style="%s"%s>%s</div>',
								esc_attr( $L['type'] ), esc_attr( $style ), $anim, $inner );
						}
					endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
			<?php if ( $s['arrows'] && count( $data['slides'] ) > 1 ) : ?>
				<button class="fbsl-arr fbsl-prev" type="button" aria-label="Előző">&#10094;</button>
				<button class="fbsl-arr fbsl-next" type="button" aria-label="Következő">&#10095;</button>
			<?php endif; ?>
			<?php if ( $s['bullets'] && count( $data['slides'] ) > 1 ) : ?>
				<div class="fbsl-dots"><?php
					foreach ( $data['slides'] as $si => $x ) {
						printf( '<button type="button" class="fbsl-dot%s" data-i="%d" aria-label="%d. slide"></button>',
							0 === $si ? ' fbsl-on' : '', $si, $si + 1 );
					}
				?></div>
			<?php endif; ?>
			<?php if ( ! empty( $s['thumbs'] ) && count( $data['slides'] ) > 1 ) :
				$tw = (int) $s['thumbW'];
				$th = (int) $s['thumbH'];
				$ta = esc_attr( $s['thumbsAlign'] );
			?>
				<div class="fbsl-thumbs fbsl-thumbs-<?php echo $ta; ?>"
					style="<?php
						if ( in_array( $ta, [ 'bottom', 'top' ], true ) ) echo "height:{$th}px;";
						else echo "width:{$tw}px;";
					?>">
					<?php foreach ( $data['slides'] as $si => $slide ) :
						$thumb = isset( $slide['_thumbImg'] ) && $slide['_thumbImg'] ? $slide['_thumbImg']
							: ( $slide['bg'] ?: '' );
						// per-slide thumb override from first layer thumbImg
						foreach ( $slide['layers'] as $ly ) {
							if ( ! empty( $ly['thumbImg'] ) ) { $thumb = $ly['thumbImg']; break; }
						}
					?>
					<button type="button"
						class="fbsl-thumb<?php echo 0 === $si ? ' fbsl-on' : ''; ?>"
						data-i="<?php echo $si; ?>"
						style="width:<?php echo $tw; ?>px;height:<?php echo $th; ?>px;<?php
							echo $thumb ? 'background-image:url(' . esc_url( $thumb ) . ');' : '';
						?>"
						aria-label="<?php echo ( $si + 1 ); ?>. slide"></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Render slider HTML from a data array (used by shortcode + gallery intercept). */
	private function render_slider_html( $data, $uid_suffix = '' ) {
		$data = $this->sanitize_data( $data );
		if ( empty( $data['slides'] ) ) return '';
		$uid = 'fbsl-' . ( $uid_suffix ?: uniqid() );
		// Re-use shortcode output by temporarily setting up the same variables
		$s = $data['settings'];
		$allowed_html = [
			'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [],
			'span' => [ 'style' => true, 'class' => true ],
		];
		ob_start();
		$this->print_assets();
		$first_bg = isset( $data['slides'][0]['bg'] ) ? $data['slides'][0]['bg'] : '';
		if ( $first_bg ) {
			add_action( 'wp_head', function() use ( $first_bg ) {
				printf( '<link rel="preload" as="image" href="%s" fetchpriority="high">' . "\n", esc_url( $first_bg ) );
			}, 1 );
		}
		?>
		<div class="fbsl" id="<?php echo esc_attr( $uid ); ?>"
			data-fbsl="<?php echo esc_attr( wp_json_encode( [
				'w' => $s['width'], 'h' => $s['height'], 'delay' => $s['delay'],
				'tr' => $s['transition'], 'auto' => $s['autoplay'], 'loop' => $s['loop'],
				'hover' => $s['pauseHover'],
			] ) ); ?>"
			style="background:<?php echo esc_attr( $s['bgColor'] ); ?>;">
			<div class="fbsl-stage">
			<?php foreach ( $data['slides'] as $si => $slide ) :
				$is_first_slide = ( 0 === $si );
			?>
				<div class="fbsl-slide<?php echo $is_first_slide ? ' fbsl-on' : ''; ?>"
					data-delay="<?php echo (int) $slide['delay']; ?>"
					<?php if ( $slide['bg'] && ! $is_first_slide ) : ?>data-bg="<?php echo esc_url( $slide['bg'] ); ?>"<?php endif; ?>
					style="<?php echo $slide['bgColor'] ? 'background-color:' . esc_attr( $slide['bgColor'] ) . ';' : ''; ?>">
					<?php if ( $is_first_slide && $slide['bg'] ) : ?>
					<img class="fbsl-bgimg" src="<?php echo esc_url( $slide['bg'] ); ?>" alt="" fetchpriority="high" decoding="async" aria-hidden="true">
					<?php endif; ?>
					<div class="fbsl-grid">
					<?php foreach ( $slide['layers'] as $L ) :
						if ( 'url' === $L['type'] && ! $L['link'] ) continue;
						$style = sprintf( 'left:%dpx;top:%dpx;', (int) $L['x'], (int) $L['y'] );
						if ( 'url' === $L['type'] ) {
							$style .= sprintf( 'width:%dpx;height:%dpx;', (int) ( $L['w'] ?: 300 ), (int) ( $L['h'] ?: 200 ) );
						} elseif ( 'image' !== $L['type'] ) {
							$style .= sprintf( 'font-size:%dpx;font-weight:%d;color:%s;', (int) $L['fontSize'], (int) $L['fontWeight'], esc_attr( $L['color'] ) );
							if ( $L['bg'] )     $style .= 'background:' . esc_attr( $L['bg'] ) . ';';
							if ( $L['pad'] )    $style .= 'padding:' . esc_attr( $L['pad'] ) . ';';
							if ( $L['radius'] ) $style .= 'border-radius:' . (int) $L['radius'] . 'px;';
						} elseif ( $L['w'] ) { $style .= 'width:' . (int) $L['w'] . 'px;'; }
						$anim = sprintf( ' data-in="%s" data-out="%s" data-start="%d" data-dur="%d" data-end="%d"',
							esc_attr( $L['animIn'] ), esc_attr( $L['animOut'] ), (int) $L['start'], (int) $L['dur'], (int) $L['end'] );
						if ( 'image' === $L['type'] ) {
							$img_attrs = $is_first_slide ? 'fetchpriority="high" decoding="async"' : 'loading="lazy" decoding="async"';
							$inner = '<img src="' . esc_url( $L['img'] ) . '" alt="" ' . $img_attrs . '>';
						} elseif ( 'url' === $L['type'] ) { $inner = '';
						} else { $inner = wp_kses( $L['content'], $allowed_html ); }
						if ( $L['link'] ) {
							$tgt = $L['newtab'] ? ' target="_blank" rel="noopener"' : '';
							printf( '<a class="fbsl-layer fbsl-l-%s" href="%s"%s style="%s"%s>%s</a>', esc_attr( $L['type'] ), esc_url( $L['link'] ), $tgt, esc_attr( $style ), $anim, $inner );
						} else {
							printf( '<div class="fbsl-layer fbsl-l-%s" style="%s"%s>%s</div>', esc_attr( $L['type'] ), esc_attr( $style ), $anim, $inner );
						}
					endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
			<?php if ( $s['arrows'] && count( $data['slides'] ) > 1 ) : ?>
				<button class="fbsl-arr fbsl-prev" type="button" aria-label="Előző">&#10094;</button>
				<button class="fbsl-arr fbsl-next" type="button" aria-label="Következő">&#10095;</button>
			<?php endif; ?>
			<?php if ( $s['bullets'] && count( $data['slides'] ) > 1 ) : ?>
				<div class="fbsl-dots"><?php
					foreach ( $data['slides'] as $si => $x ) {
						printf( '<button type="button" class="fbsl-dot%s" data-i="%d" aria-label="%d. slide"></button>', 0 === $si ? ' fbsl-on' : '', $si, $si + 1 );
					}
				?></div>
			<?php endif; ?>
			<?php if ( ! empty( $s['thumbs'] ) && count( $data['slides'] ) > 1 ) :
				$tw = (int) $s['thumbW']; $th = (int) $s['thumbH']; $ta = esc_attr( $s['thumbsAlign'] );
			?>
				<div class="fbsl-thumbs fbsl-thumbs-<?php echo $ta; ?>"
					style="<?php echo in_array( $ta, [ 'bottom', 'top' ], true ) ? "height:{$th}px;" : "width:{$tw}px;"; ?>">
					<?php foreach ( $data['slides'] as $si => $slide ) :
						$thumb = isset( $slide['_thumbImg'] ) && $slide['_thumbImg'] ? $slide['_thumbImg'] : $slide['bg'];
						foreach ( $slide['layers'] as $ly ) { if ( ! empty( $ly['thumbImg'] ) ) { $thumb = $ly['thumbImg']; break; } }
					?>
					<button type="button" class="fbsl-thumb<?php echo 0 === $si ? ' fbsl-on' : ''; ?>"
						data-i="<?php echo $si; ?>"
						style="width:<?php echo $tw; ?>px;height:<?php echo $th; ?>px;<?php echo $thumb ? 'background-image:url(' . esc_url( $thumb ) . ');' : ''; ?>"
						aria-label="<?php echo ( $si + 1 ); ?>. slide"></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function print_assets() {
		if ( $this->assets_printed ) return;
		$this->assets_printed = true;
		?>
<style id="fbsl-css">
.fbsl{position:relative;width:100%;overflow:hidden}
.fbsl-stage{position:relative;width:100%;height:100%}
.fbsl-bgimg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center;z-index:0;display:block}
.fbsl-slide{position:absolute;inset:0;background-position:center;background-size:cover;background-repeat:no-repeat;opacity:0;visibility:hidden;transition:opacity .8s ease,transform .8s ease;z-index:1}
.fbsl-slide.fbsl-on{opacity:1;visibility:visible;z-index:2}
.fbsl[data-tr=slide] .fbsl-slide{transform:translateX(40px)}
.fbsl[data-tr=slide] .fbsl-slide.fbsl-on{transform:translateX(0)}
.fbsl-grid{position:absolute;left:50%;top:0;transform-origin:0 0}
.fbsl-layer{position:absolute;line-height:1.2;white-space:pre-wrap;opacity:0;text-decoration:none;display:inline-block;will-change:transform,opacity}
.fbsl-layer.fbsl-vis{opacity:1}
.fbsl-l-image img{display:block;max-width:100%;height:auto}
.fbsl-l-button{cursor:pointer;transition:filter .2s}
.fbsl-l-button:hover{filter:brightness(1.12)}
.fbsl-l-url{z-index:4;cursor:pointer;background:transparent}
.fbsl-l-url.fbsl-vis{opacity:1!important;transform:none!important}
.fbsl-arr{position:absolute;top:50%;transform:translateY(-50%);z-index:5;background:rgba(0,0,0,.35);color:#fff;border:0;width:44px;height:44px;border-radius:50%;font-size:18px;cursor:pointer;opacity:.7;transition:opacity .2s,background .2s;line-height:1}
.fbsl-arr:hover{opacity:1;background:rgba(0,0,0,.6)}
.fbsl-prev{left:14px}.fbsl-next{right:14px}
.fbsl-dots{position:absolute;bottom:14px;left:0;right:0;text-align:center;z-index:5}
.fbsl-dot{width:11px;height:11px;border-radius:50%;border:2px solid #fff;background:transparent;margin:0 5px;padding:0;cursor:pointer;transition:background .2s}
.fbsl-dot.fbsl-on,.fbsl-dot:hover{background:#fff}
.fbsl-thumbs{display:flex;gap:4px;background:#111;overflow:hidden;flex-shrink:0}
.fbsl-thumbs-bottom,.fbsl-thumbs-top{flex-direction:row;overflow-x:auto;width:100%}
.fbsl-thumbs-left,.fbsl-thumbs-right{flex-direction:column;overflow-y:auto;position:absolute;top:0}
.fbsl-thumbs-left{left:0}.fbsl-thumbs-right{right:0}
.fbsl-thumb{flex-shrink:0;background-size:cover;background-position:center;background-color:#333;border:2px solid transparent;cursor:pointer;opacity:.6;transition:opacity .2s,border-color .2s;padding:0}
.fbsl-thumb.fbsl-on,.fbsl-thumb:hover{opacity:1;border-color:#fff}
.fbsl-anim{transition-property:opacity,transform;transition-timing-function:cubic-bezier(.22,.61,.36,1)}
.fbsl-in-fade{opacity:0}
.fbsl-in-sft{opacity:0;transform:translateY(-50px)}
.fbsl-in-sfb{opacity:0;transform:translateY(50px)}
.fbsl-in-sfl{opacity:0;transform:translateX(-50px)}
.fbsl-in-sfr{opacity:0;transform:translateX(50px)}
.fbsl-in-lft{opacity:0;transform:translateY(-100vh)}
.fbsl-in-lfb{opacity:0;transform:translateY(100vh)}
.fbsl-in-lfl{opacity:0;transform:translateX(-100vw)}
.fbsl-in-lfr{opacity:0;transform:translateX(100vw)}
.fbsl-in-zoomin{opacity:0;transform:scale(.6)}
.fbsl-in-zoomout{opacity:0;transform:scale(1.6)}
.fbsl-shown{opacity:1;transform:none}
@media (prefers-reduced-motion:reduce){
	.fbsl-slide,.fbsl-anim{transition:none!important}
	.fbsl-layer{opacity:1!important;transform:none!important}
}
</style>
<script id="fbsl-js">
(function(){
	function init(root){
		var cfg = JSON.parse(root.getAttribute('data-fbsl'));
		root.setAttribute('data-tr', cfg.tr);
		var slides  = [].slice.call(root.querySelectorAll('.fbsl-slide'));
		var dots    = [].slice.call(root.querySelectorAll('.fbsl-dot'));
		var thumbs  = [].slice.call(root.querySelectorAll('.fbsl-thumb'));
		var cur = 0, timer = null, layerTimers = [];

		function resize(){
			var ow = root.clientWidth;
			if (!ow) return;
			var s = ow / cfg.w;
			var h = Math.round(cfg.h * Math.min(s, 1.5));
			root.style.height = h + 'px';
			slides.forEach(function(sl){
				var g = sl.querySelector('.fbsl-grid');
				if (!g) return;
				g.style.width  = cfg.w + 'px';
				g.style.height = cfg.h + 'px';
				g.style.transform = 'scale(' + (h / cfg.h) + ')';
				g.style.left = '50%';
				g.style.transformOrigin = '50% 0';
			});
		}

		function clearLayerTimers(){ layerTimers.forEach(clearTimeout); layerTimers = []; }

		function playLayers(slideEl, slideDelay){
			clearLayerTimers();
			[].slice.call(slideEl.querySelectorAll('.fbsl-layer')).forEach(function(L){
				var start = parseInt(L.dataset.start||500,10),
				    dur   = parseInt(L.dataset.dur||600,10),
				    end   = parseInt(L.dataset.end||0,10),
				    ain   = L.dataset.in  || 'fade',
				    aout  = L.dataset.out || 'fade';
				L.className = L.className.replace(/\bfbsl-(anim|shown|vis|in-[a-z]+)\b/g,'').trim();
				L.classList.add('fbsl-in-' + ain);
				void L.offsetWidth;
				layerTimers.push(setTimeout(function(){
					L.classList.add('fbsl-anim','fbsl-vis');
					L.style.transitionDuration = dur + 'ms';
					L.classList.add('fbsl-shown');
				}, start));
				var outAt = (end > 0 ? end : slideDelay - dur - 50);
				if (outAt > start + dur){
					layerTimers.push(setTimeout(function(){
						L.classList.remove('fbsl-shown');
						L.classList.remove('fbsl-in-' + ain);
						L.classList.add('fbsl-in-' + aout);
					}, outAt));
				}
			});
		}

		function delayOf(i){
			var d = parseInt(slides[i].dataset.delay||0,10);
			return d > 0 ? d : cfg.delay;
		}

		function show(i){
			slides[cur].classList.remove('fbsl-on');
			if (dots[cur])   dots[cur].classList.remove('fbsl-on');
			if (thumbs[cur]) thumbs[cur].classList.remove('fbsl-on');
			cur = (i + slides.length) % slides.length;
			slides[cur].classList.add('fbsl-on');
			if (dots[cur])   dots[cur].classList.add('fbsl-on');
			if (thumbs[cur]) {
				thumbs[cur].classList.add('fbsl-on');
				thumbs[cur].scrollIntoView({block:'nearest',inline:'nearest'});
			}
			var bg = slides[cur].dataset.bg;
			if (bg && !slides[cur].dataset.bgLoaded) {
				slides[cur].style.backgroundImage = 'url(' + bg + ')';
				slides[cur].dataset.bgLoaded = '1';
			}
			playLayers(slides[cur], delayOf(cur));
			schedule();
		}

		function schedule(){
			if (timer) clearTimeout(timer);
			if (!cfg.auto || slides.length < 2) return;
			timer = setTimeout(function(){
				if (!cfg.loop && cur === slides.length - 1) return;
				show(cur + 1);
			}, delayOf(cur));
		}

		var prev = root.querySelector('.fbsl-prev'), next = root.querySelector('.fbsl-next');
		if (prev) prev.addEventListener('click', function(){ show(cur - 1); });
		if (next) next.addEventListener('click', function(){ show(cur + 1); });
		dots.forEach(function(d){
			d.addEventListener('click', function(){ show(parseInt(d.dataset.i,10)); });
		});
		thumbs.forEach(function(t){
			t.addEventListener('click', function(){ show(parseInt(t.dataset.i,10)); });
		});
		if (cfg.hover){
			root.addEventListener('mouseenter', function(){ if (timer) clearTimeout(timer); });
			root.addEventListener('mouseleave', schedule);
		}
		var tx = null;
		root.addEventListener('touchstart', function(e){ tx = e.touches[0].clientX; }, {passive:true});
		root.addEventListener('touchend', function(e){
			if (tx === null) return;
			var dx = e.changedTouches[0].clientX - tx; tx = null;
			if (Math.abs(dx) > 50) show(cur + (dx < 0 ? 1 : -1));
		}, {passive:true});

		window.addEventListener('resize', resize);
		resize();
		playLayers(slides[0], delayOf(0));
		schedule();
	}

	function boot(){
		document.querySelectorAll('.fbsl[data-fbsl]').forEach(function(el){
			if (!el.dataset.fbslInit){ el.dataset.fbslInit = '1'; init(el); }
		});
	}

	// Lighthouse/headless: readyState may already be interactive/complete
	if (document.readyState === 'loading'){
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		// Small defer so the slider's CSS (inline <style>) is applied before resize()
		requestAnimationFrame(function(){ requestAnimationFrame(boot); });
	}

	// Re-init sliders injected dynamically (Elementor, AJAX, page builders)
	if (typeof MutationObserver !== 'undefined'){
		new MutationObserver(function(mutations){
			mutations.forEach(function(m){
				m.addedNodes.forEach(function(n){
					if (n.nodeType !== 1) return;
					if (n.matches && n.matches('.fbsl[data-fbsl]') && !n.dataset.fbslInit){
						n.dataset.fbslInit = '1'; init(n);
					}
					if (n.querySelectorAll) n.querySelectorAll('.fbsl[data-fbsl]').forEach(function(el){
						if (!el.dataset.fbslInit){ el.dataset.fbslInit='1'; init(el); }
					});
				});
			});
		}).observe(document.body, {childList:true, subtree:true});
	}
})();
</script>
		<?php
	}
}

FB_Slider_Plugin::instance();
