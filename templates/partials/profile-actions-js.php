<?php
/**
 * Partial: Inline JS for Follow + Message buttons on profile pages.
 *
 * Expects in scope:
 *   $mvs_profile_id     (int)
 *   $mvs_is_own_profile (bool)
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $mvs_profile_id ) || $mvs_is_own_profile || ! is_user_logged_in() ) {
	return;
}
?>
<script>
(function(){
	/* Follow toggle */
	var fbtn = document.querySelector('.mvs-follow-toggle');
	if (fbtn) {
		fbtn.addEventListener('click', function(){
			var userId = fbtn.getAttribute('data-user-id');
			var isFollowing = fbtn.getAttribute('data-following') === '1';
			var restUrl = fbtn.getAttribute('data-rest-url');
			var nonce = fbtn.getAttribute('data-nonce');
			fbtn.disabled = true;
			fetch(restUrl + 'users/' + userId + '/follow', {
				method: isFollowing ? 'DELETE' : 'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin'
			})
			.then(function(r){ return r.json(); })
			.then(function(data){
				fbtn.disabled = false;
				if (data.following) {
					fbtn.setAttribute('data-following', '1');
					fbtn.textContent = '<?php echo esc_js( __( 'Following', 'wpmediaverse' ) ); ?>';
					fbtn.classList.add('mvs-follow-toggle--following');
					fbtn.classList.remove('mvs-btn--primary');
				} else {
					fbtn.setAttribute('data-following', '0');
					fbtn.textContent = '<?php echo esc_js( __( 'Follow', 'wpmediaverse' ) ); ?>';
					fbtn.classList.remove('mvs-follow-toggle--following');
					fbtn.classList.add('mvs-btn--primary');
				}
				if (data.counts) {
					var stats = document.querySelector('.mvs-profile-header-stats');
					if (stats) {
						var spans = stats.querySelectorAll('span');
						if (spans[1]) {
							var strong = spans[1].querySelector('strong');
							if (strong) strong.textContent = data.counts.followers;
						}
					}
				}
			})
			.catch(function(){ fbtn.disabled = false; });
		});
	}

	/* Message button — dispatch event, messaging store handles the rest */
	var mbtn = document.querySelector('.mvs-message-btn');
	if (mbtn) {
		mbtn.addEventListener('click', function(){
			var userId = parseInt(mbtn.getAttribute('data-user-id'), 10);
			document.dispatchEvent(new CustomEvent('mvs-open-conversation', {
				detail: { userId: userId }
			}));
		});
	}
})();
</script>
<?php
