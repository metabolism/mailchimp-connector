<?php

namespace Metabolism\MailchimpConnector;

use DrewM\MailChimp\MailChimp;

class Campaign
{
	private $id;
	private $post;
	private $data;
	private $html;

	private $options;
	private $Mailchimp;

	public static $preventRecursion=false;

	/**
	 * Start up
	 */
	public function __construct()
	{
		$this->options = get_option( 'mailchimp_connector' );

		if( !is_admin() ){

			add_filter( 'pre_get_posts', function($query){

				if ($query->is_main_query() && $query->is_preview() && $query->is_singular() ){

					if ( !headers_sent() )
						nocache_headers();

					add_action( 'wp_head', 'wp_no_robots' );

					add_filter( 'get_post_status', [$this, 'getPostStatus'], 10, 2 );
					add_filter( 'posts_results', [$this, 'postsResults'], 10, 2 );
				}

				return $query;
			});
		}
		else{

			if ( !session_id() )
				session_start();

			if( !isset($this->options['api_key']) || empty($this->options['api_key']) )
				return;

			try{
				$this->Mailchimp = new MailChimp($this->options['api_key']);
			}
			catch (\Exception $e){
				wp_die($e->getMessage());
			}

			add_action( 'manage_'.$this->options['post_type'].'_posts_custom_column', [$this, 'manageColumns'], 10, 2 );
			add_filter( 'manage_edit-'.$this->options['post_type'].'_columns', [$this, 'editColumns'] ) ;

			add_action( 'save_post', [$this, 'savePost'], 10, 2 );
			add_action( 'before_delete_post', [$this, 'deletePost'], 10 );
			add_action( 'admin_notices', [$this, 'adminNotices'] );

			add_action( 'post_submitbox_misc_actions', [$this, 'miscActions'] );
		}
	}


	public function getPostStatus( $post_status, $post ) {

		if( $post->post_type == $this->options['post_type'] && in_array($post_status, ['draft', 'future']) )
			return 'publish';

		return $post_status;
	}


	public function postsResults( $posts ) {

		remove_filter( 'posts_results', [$this, 'postsResults'], 10 );

		if( empty($posts) )
			return $posts;

		if( $posts[0]->post_type == $this->options['post_type'] && in_array($posts[0]->post_status, ['draft', 'future']) )
			$posts[0]->post_status = 'publish';

		return $posts;
	}


	public function miscActions( $post ) {

		$campaing_web_id = get_post_meta( $post->ID, 'mc_campaign_web_id', true );

		echo '<style type="text/css">';
		echo '#post-body .misc-pub-revisions.size:before{ content:"\f184"}';
		echo '#post-body .misc-pub-revisions.mailchimp:before{ content:"\f465"}';
		echo '</style>';
		echo '<div class="misc-pub-section size misc-pub-revisions">'.__('Size').' : <b>'.size_format(get_post_meta($post->ID, 'mc_size', true)).'</b></div>';
		echo '<div class="misc-pub-section mailchimp misc-pub-revisions">'.__('Mailchimp').' : <b>'.($campaing_web_id?__('Connected'):__('Offline')).'</b></div>';
	}


	public function editColumns( $columns ) {

		$date = $columns['date'];
		unset($columns['date']);

		$columns['size'] = __( 'Size' );
		$columns['date'] = $date;

		return $columns;
	}


	public function manageColumns($column, $post_id) {
		switch( $column ) {
			case 'size' :
				echo size_format(get_post_meta($post_id, 'mc_size', true));
				break;
		}
	}


	public function adminNotices() {

		if( isset($_SESSION['mc_errors']) ){

			$class = 'notice notice-error';
			$title = $_SESSION['mc_errors']['title'];
			$message = $_SESSION['mc_errors']['message'];

			printf( '<div class="%1$s">', esc_attr( $class ) );
			printf( '<p><b>Mailchimp Connector : %1$s</b><br/>%2$s</p>', esc_html( $title ), esc_html( $message ) );
			if( !empty($_SESSION['mc_errors']['errors'] ))
				printf( '<pre>%1$s</pre>', print_r($_SESSION['mc_errors']['errors'], true) );
			printf( '</div>' );
			unset($_SESSION['mc_errors']);
		}
	}


	public function get($key, $default=false) {
		return isset($this->data[$key]) &&!empty($this->data[$key][0]) ? $this->data[$key][0] : $default;
	}


	private function getContent($post_id) {

		$post_url = get_preview_post_link( $post_id );
		$home_url = get_home_url(null);

		if( strpos($post_url, $home_url) === false )
			$post_url = $home_url.$post_url;

		return file_get_contents($post_url);
	}


	public function addContent($campaign_id) {

		$html = $this->html;

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

		if( !$this->get('mc_list_id') )
			return false;

		$settings = [
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
		];

		if( $campaign_id )
			$campaign = $this->Mailchimp->patch('/campaigns/'.$campaign_id, $settings);
		else
			$campaign = $this->Mailchimp->post('/campaigns', $settings);

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

		if( self::$preventRecursion || !in_array($post->post_status, ['draft', 'pending', 'future']) || $post->post_type != $this->options['post_type'] )
			return;

		$this->id = $ID;
		$this->post = $post;
		$this->data = get_post_meta($ID);

		$this->html = $this->getContent($ID);
		update_post_meta($this->id, 'mc_size', mb_strlen($this->html));

		$campaign_id = $this->createCampaign();

		if( $campaign_id ){

			update_post_meta($ID, 'mc_campaign_id', $campaign_id);

			if( $this->addContent($campaign_id) ){

				$status = false;

				if( $post->post_status == 'future' )
					$status = $this->scheduleCampaign($campaign_id);
				elseif( $post->post_status == 'publish' )
					$status = $this->sendCampaign($campaign_id);

				if( !$status && in_array($post->post_status, ['future', 'publish']) ){

					self::$preventRecursion = true;
					wp_update_post(['ID'=>$ID, 'post_status'=>'draft']);
				}
			}
		}
	}
}