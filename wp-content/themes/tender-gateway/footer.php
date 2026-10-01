<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
</main>

<footer class="tgt-footer">
	<div class="tgt-shell">
		<div class="tgt-footer__grid">
			<div class="tgt-footer__brand">
				<strong><?php bloginfo( 'name' ); ?></strong>
				<p>A government-backed gateway connecting Omani suppliers with public procurement opportunities across Africa.</p>
			</div>

			<div class="tgt-footer__col">
				<h3>Opportunities</h3>
				<ul>
					<li><a href="<?php echo esc_url( tg_page_url( 'tenders' ) ); ?>">All tenders</a></li>
					<li><a href="<?php echo esc_url( add_query_arg( 'closing', 14, tg_page_url( 'tenders' ) ) ); ?>">Closing this fortnight</a></li>
					<li><a href="<?php echo esc_url( add_query_arg( 'sort', 'value', tg_page_url( 'tenders' ) ) ); ?>">Highest value</a></li>
				</ul>
			</div>

			<div class="tgt-footer__col">
				<h3>Suppliers</h3>
				<ul>
					<li><a href="<?php echo esc_url( tg_page_url( 'register' ) ); ?>">Register</a></li>
					<li><a href="<?php echo esc_url( tg_page_url( 'login' ) ); ?>">Sign in</a></li>
					<li><a href="<?php echo esc_url( tg_page_url( 'how-it-works' ) ); ?>">How it works</a></li>
				</ul>
			</div>

			<div class="tgt-footer__col">
				<h3>The gateway</h3>
				<ul>
					<li><a href="<?php echo esc_url( tg_page_url( 'about' ) ); ?>">About</a></li>
					<li><a href="<?php echo esc_url( tg_page_url( 'contact' ) ); ?>">Contact</a></li>
				</ul>
			</div>
		</div>

		<div class="tgt-footer__base">
			<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. Prototype prepared for Ministry review.</p>
			<?php
			// Once the site is pulling real records this line has to stop calling
			// them illustrative - saying "sample data" underneath live Rwandan
			// tenders misleads a reader just as badly as the reverse would.
			$tgt_live = class_exists( 'TG_API' ) && TG_API::is_live();
			?>
			<p class="tgt-footer__note">
				<?php if ( $tgt_live ) : ?>
					Tender data is received from TendersOnTime through the gateway's tender API integration layer and refreshed automatically.
				<?php else : ?>
					Tender data shown in this prototype is illustrative and is served through the gateway's tender API integration layer.
				<?php endif; ?>
			</p>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
