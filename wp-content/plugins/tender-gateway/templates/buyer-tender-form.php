<?php
/**
 * @var array|null $tender  Existing draft, or null for a new one.
 * @var int        $user_id
 * @var array|null $flash
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$id   = $tender ? (int) $tender['id'] : 0;
$v    = function ( $key, $default = '' ) use ( $tender ) {
	return $tender && isset( $tender[ $key ] ) && null !== $tender[ $key ] ? $tender[ $key ] : $default;
};
$dt   = function ( $key ) use ( $tender ) {
	return $tender && ! empty( $tender[ $key ] ) ? date( 'Y-m-d\TH:i', strtotime( $tender[ $key ] ) ) : '';
};
$docs = $id ? TG_Docs::for_owner( 'tender', $id ) : array();
?>
<div class="tg-dash tg-buyer">
	<p><a class="tg-link" href="<?php echo esc_url( TG_Buyer::portal_url() ); ?>">&larr; Your tenders</a></p>
	<h1><?php echo $tender ? esc_html( 'Edit draft ' . $tender['external_id'] ) : 'Create a tender'; ?></h1>
	<p class="tg-note">Buyer: <strong><?php echo esc_html( TG_Buyer::organisation( $user_id ) ); ?></strong>. Fields marked * are needed before you submit; a draft can be saved at any time.</p>

	<?php if ( $flash ) : ?>
		<div class="tg-notice tg-notice--<?php echo 'error' === $flash['type'] ? 'error' : 'success'; ?>"><?php echo esc_html( $flash['message'] ); ?></div>
	<?php endif; ?>

	<form method="post" enctype="multipart/form-data" class="tg-form" id="tg-buyer-form">
		<?php wp_nonce_field( 'tg_buyer_save_' . $id, 'tg_buyer_nonce' ); ?>
		<input type="hidden" name="tg_buyer_action" value="save">
		<input type="hidden" name="tender_id" value="<?php echo esc_attr( (string) $id ); ?>">

		<fieldset class="tg-fieldset">
			<legend>What is being bought</legend>
			<label class="tg-field tg-field--block">
				<span>Title <em>*</em></span>
				<input type="text" name="title" required value="<?php echo esc_attr( $v( 'title' ) ); ?>" placeholder="e.g. Supply of 1,000 kg basmati rice">
			</label>
			<div class="tg-form__row">
				<label class="tg-field tg-field--block">
					<span>Quantity</span>
					<input type="text" name="quantity" value="<?php echo esc_attr( $v( 'quantity' ) ); ?>" placeholder="e.g. 1000">
				</label>
				<label class="tg-field tg-field--block">
					<span>Unit</span>
					<input type="text" name="unit" value="<?php echo esc_attr( $v( 'unit' ) ); ?>" placeholder="kg, tonnes, units">
				</label>
			</div>
			<div class="tg-form__row">
				<label class="tg-field tg-field--block">
					<span>Sector <em>*</em></span>
					<select name="sector">
						<option value="">- choose -</option>
						<?php foreach ( TG_Sectors::all() as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $v( 'sector' ), $s ); ?>><?php echo esc_html( $s ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="tg-field tg-field--block">
					<span>Country</span>
					<select name="country">
						<option value="">- not specified -</option>
						<?php foreach ( TG_Countries::all() as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $v( 'country', 'Oman' ), $c ); ?>><?php echo esc_html( $c ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<div class="tg-form__row">
				<label class="tg-field tg-field--block">
					<span>Delivery location</span>
					<input type="text" name="delivery_location" value="<?php echo esc_attr( $v( 'delivery_location' ) ); ?>">
				</label>
				<label class="tg-field tg-field--block">
					<span>Estimated value (OMR)</span>
					<input type="number" step="0.001" min="0" name="value" value="<?php echo esc_attr( $v( 'value', '' ) ); ?>">
					<input type="hidden" name="currency" value="OMR">
					<small>Optional. Leave empty to show "Value not disclosed".</small>
				</label>
			</div>
		</fieldset>

		<fieldset class="tg-fieldset">
			<legend>Description</legend>
			<label class="tg-field tg-field--block">
				<span>Summary <em>*</em></span>
				<textarea name="summary" rows="3"><?php echo esc_textarea( $v( 'summary' ) ); ?></textarea>
				<small>Public: anyone visiting the site can read this.</small>
			</label>
			<label class="tg-field tg-field--block">
				<span>Specifications <em>*</em></span>
				<textarea name="specifications" rows="7"><?php echo esc_textarea( $v( 'specifications' ) ); ?></textarea>
				<small>The full requirement. Only subscribed suppliers can read it.</small>
			</label>
			<label class="tg-field tg-field--block">
				<span>Additional detail</span>
				<textarea name="description" rows="3"><?php echo esc_textarea( $v( 'description' ) ); ?></textarea>
			</label>
		</fieldset>

		<fieldset class="tg-fieldset">
			<legend>Timing</legend>
			<div class="tg-form__row">
				<label class="tg-field tg-field--block">
					<span>Opening date and time</span>
					<input type="datetime-local" name="opening_at" value="<?php echo esc_attr( $dt( 'opening_at' ) ); ?>">
					<small>Leave empty to open as soon as it is published.</small>
				</label>
				<label class="tg-field tg-field--block">
					<span>Closing date and time <em>*</em></span>
					<input type="datetime-local" name="closing_at" value="<?php echo esc_attr( $dt( 'closing_at' ) ); ?>">
					<small>Bidding stops automatically at this moment (Muscat time).</small>
				</label>
			</div>
		</fieldset>

		<fieldset class="tg-fieldset">
			<legend>Documents</legend>
			<?php if ( $docs ) : ?>
				<ul style="margin:0 0 10px;padding-left:18px;">
					<?php foreach ( $docs as $d ) : ?>
						<li><a href="<?php echo esc_url( TG_Docs::url( $d ) ); ?>"><?php echo esc_html( $d['filename'] ); ?></a>
							<button type="submit" form="tg-doc-del-<?php echo (int) $d['id']; ?>" class="tg-link" style="background:none;border:0;color:#8a2424;cursor:pointer;">remove</button></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<label class="tg-field tg-field--block">
				<span>Attach documents</span>
				<input type="file" name="tg_docs[]" multiple>
				<small>Drawings, specifications, bill of quantities. PDF, Word, Excel, JPG or PNG, up to 10 MB each. Only subscribed suppliers can download them.</small>
			</label>
		</fieldset>

		<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">
			<button type="submit" name="save_draft" value="1" class="tg-btn tg-btn--ghost">Save draft</button>
			<button type="submit" name="submit_now" value="1" class="tg-btn tg-btn--accent"
				onclick="return confirm('<?php echo esc_js( 'Submit this tender to the Jumla Tender team for approval? You will not be able to edit it after submitting.' ); ?>');">
				Submit for approval
			</button>
		</div>
	</form>

	<?php foreach ( $docs as $d ) : ?>
		<form method="post" id="tg-doc-del-<?php echo (int) $d['id']; ?>" style="display:none;">
			<?php wp_nonce_field( 'tg_buyer_doc_delete_' . $id, 'tg_buyer_nonce' ); ?>
			<input type="hidden" name="tg_buyer_action" value="doc_delete">
			<input type="hidden" name="tender_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="doc_id" value="<?php echo (int) $d['id']; ?>">
		</form>
	<?php endforeach; ?>
</div>
