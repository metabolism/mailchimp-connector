<?php

namespace Metabolism\MailchimpConnector;

use DrewM\MailChimp\MailChimp;

class Campaign
{
	private $id;
	private $post;
	private $data;

	private $options;
	private $Mailchimp;

	public static $preventRecursion=false;

	/**
	 * Start up
	 */
	public function __construct()
	{
		if( !is_admin() )
			return;

		if ( !session_id() )
			session_start();

		$this->options = get_option( 'mailchimp_connector' );

		if( !isset($this->options['api_key']) || empty($this->options['api_key']) )
			return;

		try{
			$this->Mailchimp = new MailChimp($this->options['api_key']);
		}
		catch (\Exception $e){
			wp_die($e->getMessage());
		}

		add_action( 'save_post', [$this, 'savePost'], 10, 2 );
		add_action( 'before_delete_post', [$this, 'deletePost'], 10 );
		add_action( 'admin_notices', [$this, 'adminNotices'] );
	}


	public function adminNotices() {

		if( isset($_SESSION['mc_errors']) ){

			$class = 'notice notice-error';
			$title = $_SESSION['mc_errors']['title'];
			$message = $_SESSION['mc_errors']['message'];

			printf( '<div class="%1$s"><p><b>Mailchimp Connector : %2$s</b><br/>%3$s</p></div>', esc_attr( $class ), esc_html( $title ), esc_html( $message ) );
			unset($_SESSION['mc_errors']);
		}
	}


	public function get($key, $default=false) {
		return isset($this->data[$key]) ? $this->data[$key][0] : $default;
	}


	public function addContent($campaign_id) {

		$post_url = get_preview_post_link( $this->id );
		$home_url = get_home_url(null);

		if( strpos($post_url, $home_url) === false )
			$post_url = $home_url.$post_url;

		$html = file_get_contents($post_url);

		if( empty($html) )
			return false;

		$content = $this->Mailchimp->put('/campaigns/'.$campaign_id.'/content', [
			'html'=> $html,
			'plain_text'=> $this->get('plain_text', '')
		]);

		if( !isset($content['html']) )
			return $this->setError($content);

		return true;
	}

	public function createCampaign() {

		$campaign_id = get_post_meta($this->id, 'mc_campaign_id', true);

		if( $campaign_id )
			return $campaign_id;

		$campaign = $this->Mailchimp->post('/campaigns', [
			'recipients' =>[
				'list_id'=>$this->get('mc_list_id'),
				'segment_opts'=>[
					'saved_segment_id'=>intval($this->get('mc_segment_id'))
				]
			],
			'type'=>'regular',
			'settings'=>[
				'subject_line' => $this->get('mc_list_subject', $this->post->post_title),
				'preview_text' => $this->get('mc_list_preview', ''),
				'title' => $this->post->post_title,
				'reply_to' => $this->options['reply'],
				'from_name' => $this->options['from'],
				'inline_css' => true
			]
		]);

		if( !isset($campaign['id']) )
			return $this->setError($campaign);

		update_post_meta($this->id, 'mc_campaign_web_id', $campaign['web_id']);

		return $campaign['id'];
	}


	public function setError($return) {

		$_SESSION['mc_errors'] = ['title'=>$return['title'], 'message'=>$return['detail'], 'errors'=>isset($return['errors'])?$return['errors']:''];

		return false;
	}

	public function sendCampaign($campaign_id) {

		$status = $this->Mailchimp->post('/campaigns/'.$campaign_id.'/actions/send');

		if( isset($status['type']) )
			return $this->setError($status);

		return true;
	}

	public function scheduleCampaign($campaign_id) {

		$time = strtotime($this->post->post_date_gmt);
		$time = round($time / (15 * 60)) * (15 * 60);

		$status = $this->Mailchimp->post('/campaigns/'.$campaign_id.'/actions/schedule', [
			'schedule_time'=> date('c', $time)
		]);

		if( isset($status['type']) )
			return $this->setError($status);

		return true;
	}

	public function deletePost( $ID ) {

		$campaign_id = get_post_meta($ID, 'mc_campaign_id', true);

		if( $campaign_id ){

			$status = $this->Mailchimp->delete('/campaigns/'.$campaign_id);

			if( isset($status['type']) )
				return $this->setError($status);
		}

		return true;
	}

	public function savePost( $ID, $post ) {

		if( self::$preventRecursion || $post->post_status == 'auto-draft' || $post->post_type != $this->options['post_type'] )
			return;

		$this->id = $ID;
		$this->post = $post;
		$this->data = get_post_meta($ID);

		$campaign_id = $this->createCampaign();

		if( $campaign_id ){

			update_post_meta($ID, 'mc_campaign_id', $campaign_id);

			if( $this->addContent($campaign_id) ){

				$status = false;

				if( $post->post_status == 'future' )
					$status = $this->scheduleCampaign($campaign_id);
				elseif( $post->post_status == 'publish' )
					$status = $this->sendCampaign($campaign_id);

				if( !$status && ($post->post_status == 'future' || $post->post_status == 'publish') ){

					self::$preventRecursion = true;
					wp_update_post(['ID'=>$ID, 'post_status'=>'draft']);
				}
			}
		}
	}
}