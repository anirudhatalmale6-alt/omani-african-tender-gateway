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
			<p class="tgt-footer__note">Tender data shown in this prototype is illustrative and is served through the gateway's tender API integration layer.</p>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
