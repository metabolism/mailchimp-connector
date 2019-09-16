<?php

namespace Metabolism\MailchimpConnector;

use DrewM\MailChimp\MailChimp;

class MetaBox
{
	private $options;
	private $Mailchimp;

	/**
	 * Start up
	 */
	public function isSelectedPostType()
	{
		global $pagenow;

		$post_type = $this->options['post_type']??'post';
		return ('post.php' === $pagenow && isset($_GET['post']) && $post_type === get_post_type( $_GET['post'] ) ) || ('post-new.php' === $pagenow && isset($_GET['post_type']) && $_GET['post_type'] == $post_type );
	}


	/**
	 * Start up
	 */
	public function __construct()
	{
		if( !is_admin() )
			return;

		$this->options = get_option( 'mailchimp_connector' );

		// segments
		add_action( 'wp_ajax_mc_get_segment', [$this, 'ajaxGetSegments'] );

		// action on saving post
		add_action( 'save_post', [$this, 'saveMeta'] );

		add_filter('post_row_actions', function($actions, $post ){
			if ( $this->options['post_type'] === $post->post_type && $post->post_status == 'publish' ) {
				unset( $actions['inline hide-if-no-js'] );
			}
			return $actions;
		}, 10, 2);

		if( !$this->isSelectedPostType() || !isset($this->options['api_key']) || empty($this->options['api_key']) )
			return;

		try{

			$this->Mailchimp = new MailChimp($this->options['api_key']);
		}
		catch (\Exception $e){

			add_action( 'admin_notices', function() use ($e) {
				echo '<div class="error notice"><p>'.$e->getMessage().'</p></div>';
			});
		}

		// action to add meta boxes
		add_action( 'add_meta_boxes', [$this, 'addMetabox'] );

		// js script
		add_action( 'admin_footer', [$this, 'adminFooter'] );

		add_action( 'post_submitbox_misc_actions', [$this, 'miscActions'] );
	}


	public function miscActions( $post ) {

		$campaing_web_id = get_post_meta( $post->ID, 'mc_campaign_web_id', true );
		$size = get_post_meta($post->ID, 'mc_size', true);
		echo '<style type="text/css">';
		echo '#post-body .misc-pub-revisions.size:before{ content:"\f184"}';
		echo '#post-body .misc-pub-revisions.mailchimp:before{ content:"\f465"}';
		echo '</style>';
		echo '<div class="misc-pub-section size misc-pub-revisions">'.__('Size').' : <b>'.size_format($size?$size:0).'</b></div>';
		echo '<div class="misc-pub-section mailchimp misc-pub-revisions">'.__('Mailchimp').' : <b>'.($campaing_web_id?__('Connected'):__('Offline')).'</b></div>';
	}

	public function ajaxGetSegments() {

		try{
			$Mailchimp = new MailChimp($this->options['api_key']);
		}
		catch (\Exception $e){

			wp_send_json(['succes'=>0, 'message'=> $e->getMessage()]);
		}

		$list_id = sanitize_text_field( $_POST['list_id'] );
		$mailchimp_data = $Mailchimp->get('/lists/'.$list_id.'/segments');

		wp_send_json(['succes'=>1, 'segments'=>$mailchimp_data['segments']]);
	}

	public function adminFooter() {
		?>
		<style type="text/css">
			#mailchimp-connector label{ margin-top: 10px; margin-bottom: 5px; display: inline-block }
			#mailchimp-connector input{ width: 100% }
			#mailchimp_link{ float: right }
			#post-body .button-disabled{ pointer-events: none }
		</style>

		<script type="text/javascript">
			jQuery(document).ready(function($) {

				var status = '<?=get_post_status( get_the_ID() )?>';
				var mc_segment_id = '<?=get_post_meta( get_the_ID(), 'mc_segment_id', true )?>';
				var member = '<?=__('member','mc')?>';
				var members = '<?=__('members','mc')?>';

				if( status == 'publish' ){

					$('#publish').val('<?=__('Sent')?>');
					$('#post-body').find('input, select, textarea').attr('disabled','disabled');
					$('#post-body').find('.button').addClass('button-disabled');
					$('.edit-post-status, .edit-visibility, .edit-timestamp').remove();
				}
				else{

					var clicked = false;
					$('#publish').click(function(){
						if( clicked || confirm("Do you really want to send this newsletter? Else use the save draft option.") ){
							clicked = true;
							return true
						}
						else
							return  false;
					});

					$('#mc_list_id').change(function(){

						if( !$(this).val().length ){

							$('#mc_segments').empty();
							return;
						}

						jQuery.post(ajaxurl, {'action':'mc_get_segment', 'list_id':$(this).val()}, function(response) {

							if( response.succes && response.segments.length ){

								var html = '<label for="mc_segment_id"><?=__('Segment','mc'); ?></label><select name="mc_segment_id" id="mc_segment_id">';
								html += '<option value="0"><?=__('All','mc')?></option>';
								for(var i=0; i<response.segments.length; i++){
									var segment = response.segments[i];
									html += '<option value='+segment.id+' '+(mc_segment_id==segment.id?'selected':'')+'>'+segment.name+' ( '+segment.member_count+' '+(segment.member_count>1?members:member)+' )</option>';
								}
								html += '</segment>';

								$('#mc_segments').html(html);
							}
							else{

								$('#mc_segments').empty();
							}
						});

					}).change();
				}
			});
		</script> <?php
	}

	public function addMetabox( $post ) {

		$campaing_web_id = get_post_meta( get_the_ID(), 'mc_campaign_web_id', true );

		add_meta_box(
			'mailchimp-connector',
			__( 'Mailchimp Connector' ).($campaing_web_id?' <a id="mailchimp_link" target="_blank" href="https://us4.admin.mailchimp.com/campaigns/edit?id='.$campaing_web_id.'">'.__('View on Mailchimp', 'mc').'</a>':''),
			function(){

				wp_nonce_field( basename( __FILE__ ), 'mc_nonce' );

				$mailchimp_data = $this->Mailchimp->get('/lists');
				$lists = $mailchimp_data['lists']??[];
				$dropdown_value = get_post_meta( get_the_ID(), 'mc_list_id', true );

				$member = __('member', 'mc');
				$members = __('members', 'mc');

				echo '<label for="mc_list_id">'.__('List','mc').'*</label><select required name="mc_list_id" id="mc_list_id">';
				echo '<option></option>';

				foreach ($lists as $list){

					$member_count = $list['stats']['member_count'];
					$id = $list['id'];
					$name = $list['name'];

					echo '<option value="'.$id.'" '.($dropdown_value == $id?'selected':'').'>'.$name.' ( '.$member_count.' '.($member_count>1?$members:$member).' )</option>';
				}
				echo '</select>';
				echo '<div id="mc_segments"></div>';

				echo '<label for="mc_list_subject">'.__('Subject', 'mc').'</label>';
				echo '<input type="text" name="mc_list_subject" maxlength="150" value="'.get_post_meta( get_the_ID(), 'mc_list_subject', true ).'" placeholder="Title will be used if empty"/>';

				echo '<label for="mc_list_preview">'.__('Preview', 'mc').'</label>';
				echo '<input type="text" name="mc_list_preview" maxlength="150" value="'.get_post_meta( get_the_ID(), 'mc_list_preview', true ).'"/>';
			},
			$this->options['post_type']??'post',
			'normal',
			'high'
		);

	}

	// dropdown saving
	public function saveMeta( $post_id ) {

		// if doing autosave don't do nothing
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;

		// verify nonce
		if ( !isset($_POST['mc_nonce']) || !wp_verify_nonce( $_POST['mc_nonce'], basename( __FILE__ ) ) )
			return;


		// Check permissions
		if ( 'page' == $_POST['post_type'] )
		{
			if ( !current_user_can( 'edit_page', $post_id ) )
				return;
		}
		else
		{
			if ( !current_user_can( 'edit_post', $post_id ) )
				return;
		}

		// save the new value of the dropdown
		if( isset($_POST['mc_list_id']) )
			update_post_meta( $post_id, 'mc_list_id', sanitize_text_field($_POST['mc_list_id']) );

		if( isset($_POST['mc_segment_id']) )
			update_post_meta( $post_id, 'mc_segment_id', intval($_POST['mc_segment_id']) );

		if( isset($_POST['mc_list_subject']) )
			update_post_meta( $post_id, 'mc_list_subject', sanitize_text_field($_POST['mc_list_subject']) );

		if( isset($_POST['mc_list_preview']) )
			update_post_meta( $post_id, 'mc_list_preview', sanitize_text_field($_POST['mc_list_preview']) );
	}
}