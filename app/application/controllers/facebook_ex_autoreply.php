<?php

require_once("home.php"); // loading home controller

class facebook_ex_autoreply extends Home
{

    public $user_id;    
    
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1)
        redirect('home/login_page', 'location');   
        if($this->session->userdata('user_type') != 'Admin' && !in_array(80,$this->module_access))
        redirect('home/login_page', 'location'); 
        $this->user_id=$this->session->userdata('user_id');

        if($this->session->userdata("facebook_rx_fb_user_info")==0)
        redirect('facebook_rx_account_import/index','refresh');
    
        $this->load->library("fb_rx_login");
        $this->important_feature();
        $this->member_validity();        
    }


    public function index()
    {
      $this->get_page_list();
    }


    public function get_page_list()
    {
        $data['body'] = 'facebook_ex/auto_reply/auto_reply_page_list';
        $data['page_title'] = $this->lang->line('Auto reply - Page list');
        $page_info = array();
        $page_list = $this->basic->get_data("facebook_rx_fb_page_info",array("where"=>array("user_id"=>$this->user_id,"facebook_rx_fb_user_info_id"=>$this->session->userdata("facebook_rx_fb_user_info"))),"","","","","page_name asc");
        if(!empty($page_list))
        {
            $i = 0;
            foreach($page_list as $value)
            {
                $autoreply_info = $this->basic->get_data('facebook_ex_autoreply',array('where'=>array('page_info_table_id'=>$value['id'])),'','','','','last_reply_time desc');

                $autoreply_count = $this->basic->get_data('facebook_ex_autoreply',array('where'=>array('page_info_table_id'=>$value['id'])),array('sum(auto_private_reply_count) as auto_private_reply_count'));
                
                $page_info[$i]['id'] = $value['id'];
                $page_info[$i]['page_profile'] = $value['page_profile'];
                $page_info[$i]['page_name'] = $value['page_name'];
                $page_info[$i]['auto_reply_enabled_post'] = count($autoreply_info);
                if(empty($autoreply_info))
                    $page_info[$i]['last_reply_time'] = '0000-00-00 00:00:00';
                else
                    $page_info[$i]['last_reply_time'] = $autoreply_info[0]['last_reply_time'];

                if(empty($autoreply_count))
                    $page_info[$i]['autoreply_count'] = 0;
                else
                    $page_info[$i]['autoreply_count'] = $autoreply_count[0]['auto_private_reply_count'];

                $i++;
            }
        }

        $data["page_info"] = $page_info;
        $data['emotion_list'] = $this->get_emotion_list();
        $this->_viewcontroller($data);
    }


    public function get_emotion_list()
    {
        $dirTree=$this->scanAll(FCPATH."assets/images/emotions-fb");
        $map = array
        (
            "angel" => "o:)",
            "colonthree" => ":3",
            "confused" => "o.O",
            "cry" => ":'(",
            "devil" => "3:)",
            "frown" => ":(",
            "gasp" => ":O",
            "glasses" => "8)",
            "grin" => ":D",
            "grumpy" => ">:(",
            "heart" => "<3",
            "kiki" => "^_^",
            "kiss" => ":*",
            "pacman" => ":v",
            "smile" => ":)",
            "squint" => "-_-",
            "sunglasses" => "8|",
            "tongue" => ":p",
            "upset" => ">:O",
            "wink" => ";)"
            );
        $str = "";
        foreach ($dirTree as $value) 
        {
            $temp = array();
            $value['file'] = str_replace('\\','/', $value['file']);
            $temp =explode('/', $value["file"]);
            $filename = array_pop($temp);

            if(!strpos($filename,'.gif')) continue;

            $title = str_replace('.gif',"",$filename);
            $eval = $map[$title];

            $src= base_url('assets/images/emotions-fb/'.$filename);
            $str.= '&nbsp;&nbsp;<img eval="'.$eval.'" title="'.$title.'" style="cursor:pointer;"  class="emotion inline" src="'.$src.'"/>&nbsp;&nbsp;';
        }
        return $str;
    }

    public function scanAll($myDir)
    {
        $dirTree = array();
        $di = new RecursiveDirectoryIterator($myDir,RecursiveDirectoryIterator::SKIP_DOTS);

        $i=0;
        foreach (new RecursiveIteratorIterator($di) as $filename) {

            $dir = str_replace($myDir, '', dirname($filename));
            $dir = str_replace('/', '>', substr($dir,1));

            $org_dir=str_replace("\\", "/", $dir);

            if($org_dir)
                $file_path = $org_dir. "/". basename($filename);
            else
                $file_path = basename($filename);

            $file_full_path=$myDir."/".$file_path;
            $file_size= filesize($file_full_path);
            $file_modification_time=filemtime($file_full_path);

            $dirTree[$i]['file'] = $file_full_path;
            $dirTree[$i]['size'] = $file_size;
            $dirTree[$i]['time'] =date("Y-m-d H:i:s",$file_modification_time);

            $i++;

        }

        return $dirTree;
    }



    public function import_latest_post()
    {
        $table_id = $this->input->post('table_id');
        $page_info = $this->basic->get_data("facebook_rx_fb_page_info",array("where"=>array("id"=>$table_id)));
        $respnse = array();
        $respnse['page_name'] = $page_info[0]['page_name'];
        $page_table_id = $page_info[0]['id'];

        $existing_data = array();
        $existing_data_info = $this->basic->get_data('facebook_ex_autoreply',array('where'=>array('user_id'=>$this->user_id,'page_info_table_id'=>$page_table_id)));

        if(!empty($existing_data_info))
        {
            foreach($existing_data_info as $value)
            {
                $existing_data[$value['post_id']] = $value['id'];
            }
        }

        $page_id = $page_info[0]['page_id'];
        $access_token = $page_info[0]['page_access_token'];


        try
        {
            $post_list = $this->fb_rx_login->get_postlist_from_fb_page($page_id,$access_token);

            if(isset($post_list['data']) && empty($post_list['data'])){
                $respnse['message'] = "<h3><div class='alert alert-danger text-center'>There is no post on this page.</div></h3>";
            }
            else if(!isset($post_list['data']))
            {
                $respnse['message'] = "<h3><div class='alert alert-danger text-center'>Something went wrong, please try again.</div></h3>";
            }
            else
            {
                $str = "<div class='table-responsive'>                    
                            <table class='table table-bordered table-striped'>
                                <caption>Latest 25 posts of this page</caption>
                                <thead>
                                    <tr>
                                        <th>SL</th>
                                        <th>Post ID</th>
                                        <th>Created Time</th>
                                        <th style='width:170px'>Action</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody>";
                $i = 1;
                foreach($post_list['data'] as $value)
                {
                    $message = isset($value['message']) ? $value['message'] : '';
                    // if(strlen($message) >= 140)
                    //     $message = substr($message, 0, 140);
                    // else $message = $message;

                    if(array_key_exists($value['id'], $existing_data))
                    {
                        $button = "<button class='btn btn-sm btn-danger'>Enabled</button> &nbsp;&nbsp; <button class='btn btn-sm btn-info edit_reply_info' table_id='".$existing_data[$value['id']]."'><i class='fa fa-edit'></i> Edit</button>";
                    }
                    else
                        $button = "<button class='btn btn-sm btn-success enable_auto_commnet' manual_enable='no' page_table_id='".$table_id."' post_id='".$value['id']."'>Enable auto reply</button>";
                    $str .= "
                                <tr>
                                    <td>".$i."</td>
                                    <td>".$value['id']."</td>
                                    <td>".$value['created_time']."</td>
                                    <td>".$button."</td>
                                    <td>".$message."</td>
                                </tr>
                            ";
                    $i++;                
                }
                $str .= "
                        </tbody>
                    </table>
                </div>";

                $respnse['message'] = $str;
            }

        }
        catch(Exception $e) 
        {
          $error_msg = "<div class='alert alert-danger text-center'><i class='fa fa-remove'></i> ".$e->getMessage()."</div>";
          $respnse['message'] = $error_msg;
        }

        echo json_encode($respnse);

    }


    public function checking_post_id()
    {
        
        $post_id = $this->input->post('post_id');
        $page_table_id = $this->input->post('page_table_id');
        $page_info = $this->basic->get_data('facebook_rx_fb_page_info',array('where'=>array('id'=>$page_table_id)));
        $page_name = $page_info[0]['page_name'];
        $access_token = $page_info[0]['page_access_token'];

        try
        {
            $post_info = $this->fb_rx_login->get_post_info_by_id($post_id,$access_token);
            if(isset($post_info['error']))
            {
                $response['error'] = 'yes';
                $response['error_msg'] = $post_info['error']['message'];
            }
            else
                $response['error'] = 'no';

        }
        catch(Exception $e)
        {
            $response['error'] = 'yes';
            $response['error_msg'] = $e->getMessage();
        }
        echo json_encode($response);
    }


    public function get_tableid_by_postid()
    {
        $page_table_id = $this->input->post('page_table_id');
        $post_id = $this->input->post('post_id');

        $where['where'] = array(
            'user_id' => $this->user_id,
            'page_info_table_id' => $page_table_id,
            'post_id' => $post_id
            );

        $table_info = $this->basic->get_data('facebook_ex_autoreply',$where,'','',1);
        if(empty($table_info))
            $respnse['error'] = 'yes';
        else
        {
            $respnse['error'] = 'no';
            $respnse['table_id'] = $table_info[0]['id'];
        }
        echo json_encode($respnse);
    }


    public function ajax_autoreply_submit()
    {
        if($_POST)
        {
            $post=$_POST;
            foreach ($post as $key => $value) 
            {
                $$key=$value;
            }
        }


        //************************************************//

        $status=$this->_check_usage($module_id=80,$request=1);
        if($status=="2") 
        {
            $error_msg = $this->lang->line("sorry, your bulk limit is exceeded for this module.")."<a href='".site_url('payment/usage_history')."'>".$this->lang->line("click here to see usage log")."</a>";
            $return_val=array("status"=>"0","message"=>$error_msg);
            echo json_encode($return_val);
            exit();
        }
        else if($status=="3") 
        {
            $error_msg = $this->lang->line("sorry, your monthly limit is exceeded for this module.")."<a href='".site_url('payment/usage_history')."'>".$this->lang->line("click here to see usage log")."</a>";
            $return_val=array("status"=>"0","message"=>$error_msg);
            echo json_encode($return_val);
            exit();
        }
        //************************************************//

        

        $page_info = $this->basic->get_data('facebook_rx_fb_page_info',array('where'=>array('id'=>$auto_reply_page_id)));
        $page_name = $page_info[0]['page_name'];

        // $manual_reply_description = "";

        if($manual_enable == 'yes')
        {
            try
            {
                $post_info = $this->fb_rx_login->get_post_info_by_id($auto_reply_post_id,$page_info[0]['page_access_token']);

                if(isset($post_info['error']))
                {
                    $response['error'] = 'yes';
                    $response['error_msg'] = $post_info['error']['message'];
                }
                else
                {
                    $post_created_at = isset($post_info[$auto_reply_post_id]['created_time']) ? $post_info[$auto_reply_post_id]['created_time'] : "";
                    if(isset($post_info[$auto_reply_post_id]['message']))
                        $post_description = isset($post_info[$auto_reply_post_id]['message']) ? $post_info[$auto_reply_post_id]['message'] : "";
                    else if(isset($post_info[$auto_reply_post_id]['name']))
                        $post_description = isset($post_info[$auto_reply_post_id]['name']) ? $post_info[$auto_reply_post_id]['name'] : "";
                    else
                        $post_description = isset($post_info[$auto_reply_post_id]['description']) ? $post_info[$auto_reply_post_id]['description'] : "";
                }

            }
            catch(Exception $e)
            {
                $post_created_at = "";
                $post_description = "";
            }
        }
        else
        {
            try{

                $post_list = $this->fb_rx_login->get_postlist_from_fb_page($page_info[0]['page_id'],$page_info[0]['page_access_token']);
                if(isset($post_list['data']) && !empty($post_list['data']))
                {
                    foreach($post_list['data'] as $value)
                    {
                        if($value['id'] == $auto_reply_post_id)
                        {
                            $post_created_at = $value['created_time'];
                            $post_description = isset($value['message']) ? $value['message'] : '';
                            // $manual_reply_description = "found";
                            break;
                        }
                    }
                }
            }
            catch(Exception $e)
            {            
                $post_created_at = "";
                $post_description = "";
            }
        }


        // if($manual_reply_description == '')
        // {
        //     $return['status'] = 0;
        //     $return['message'] = "<div class='alert alert-danger'>The post ID you have given is not associated with page (".$page_name.")</div>";
        //     echo json_encode($return);
        //     exit();
        // }

        $post_description = $this->db->escape($post_description);
        

        $return = array();
        $facebook_rx_fb_user_info = $this->session->userdata("facebook_rx_fb_user_info");
        $date_time = date("Y-m-d H:i:s");

        $nofilter_array['comment_reply'] = trim($nofilter_word_found_text);
        $nofilter_array['private_reply'] = trim($nofilter_word_found_text_private);
        $no_filter_array = array();
        array_push($no_filter_array, $nofilter_array);
        $nofilter_word_found_text = json_encode($no_filter_array);
        $nofilter_word_found_text = $this->db->escape($nofilter_word_found_text);

        $page_name = $this->db->escape($page_name);

        if($message_type == 'generic')
        {
            $generic_message_array['comment_reply'] = trim($generic_message);
            $generic_message_array['private_reply'] = trim($generic_message_private);
            $generic_array = array();
            array_push($generic_array, $generic_message_array);
            $auto_reply_text = '';
            $auto_reply_text = json_encode($generic_array);
            $auto_reply_text = $this->db->escape($auto_reply_text); 

            $sql = "INSERT INTO facebook_ex_autoreply (facebook_rx_fb_user_info_id,user_id,auto_reply_campaign_name,page_info_table_id,page_name,post_id,post_created_at,post_description,reply_type,auto_like_comment,multiple_reply,comment_reply_enabled,auto_reply_text,last_updated_at,auto_private_reply_done_ids,auto_reply_done_info,nofilter_word_found_text) VALUES ('$facebook_rx_fb_user_info','$this->user_id','$auto_campaign_name','$auto_reply_page_id',$page_name,'$auto_reply_post_id','$post_created_at',$post_description,'$message_type','$auto_like_comment','$multiple_reply','$comment_reply_enabled',$auto_reply_text,'$date_time','[]','[]',$nofilter_word_found_text)
            ON DUPLICATE KEY UPDATE auto_reply_text=$auto_reply_text,reply_type='$message_type',auto_like_comment='$auto_like_comment',multiple_reply='multiple_reply',comment_reply_enabled='$comment_reply_enabled',auto_reply_campaign_name='$auto_campaign_name',nofilter_word_found_text=$nofilter_word_found_text";
        }
        else
        {
            $auto_reply_text_array = array();
            for($i=1;$i<=10;$i++)
            {
                $filter_word = 'filter_word_'.$i;
                $filter_word_text = $this->input->post($filter_word);
                $filter_message = 'filter_message_'.$i;
                $filter_message_text = $this->input->post($filter_message);

                // added 25-04-2017
                $comment_message = 'comment_reply_msg_'.$i;
                $comment_message_text = $this->input->post($comment_message);

                if($filter_word_text != '' && ($filter_message_text != '' || $comment_message_text != ''))
                {
                    // $auto_reply_text_array[$filter_word_text] = $filter_message_text;
                    $data['filter_word'] = trim($filter_word_text);
                    $data['reply_text'] = trim($filter_message_text);
                    $data['comment_reply_text'] = trim($comment_message_text);
                    array_push($auto_reply_text_array, $data);
                }
            }
            $auto_reply_text = '';
            $auto_reply_text = json_encode($auto_reply_text_array);
            $auto_reply_text = $this->db->escape($auto_reply_text);

            $sql = "INSERT INTO facebook_ex_autoreply (facebook_rx_fb_user_info_id,user_id,auto_reply_campaign_name,page_info_table_id,page_name,post_id,post_created_at,post_description,reply_type,auto_like_comment,multiple_reply,comment_reply_enabled,auto_reply_text,last_updated_at,auto_private_reply_done_ids,auto_reply_done_info,nofilter_word_found_text) VALUES ('$facebook_rx_fb_user_info','$this->user_id','$auto_campaign_name','$auto_reply_page_id',$page_name,'$auto_reply_post_id','$post_created_at',$post_description,'$message_type','$auto_like_comment','$multiple_reply','$comment_reply_enabled',$auto_reply_text,'$date_time','[]','[]',$nofilter_word_found_text)
            ON DUPLICATE KEY UPDATE auto_reply_text=$auto_reply_text,reply_type='$message_type',auto_like_comment='$auto_like_comment',multiple_reply='$multiple_reply',comment_reply_enabled='$comment_reply_enabled',auto_reply_campaign_name='$auto_campaign_name',nofilter_word_found_text=$nofilter_word_found_text";
        }

        

        if($this->db->query($sql))
        {
            //insert data to useges log table
            $this->_insert_usage_log($module_id=80,$request=1);
            $return['status'] = 1;
            $return['message'] = "<div class='alert alert-success'>Your given information has been saved successfully.</div>";
        }
        else
        {
            $return['status'] = 0;
            $return['message'] = "<div class='alert alert-danger'>Something went wrong, please try again.</div>";
        }
        echo json_encode($return);
    }


    public function ajax_autoreply_delete()
    {
        $table_id = $this->input->post('table_id');
        $post_info = $this->basic->get_data('facebook_ex_autoreply',array('where'=>array('id'=>$table_id)));
        if($post_info[0]['auto_private_reply_count'] == 0)
        {
            //******************************//
            // delete data to useges log table
            $this->_delete_usage_log($module_id=80,$request=1);   
            //******************************//
        }

        $this->basic->delete_data('facebook_ex_autoreply',array('id'=>$table_id));
        echo 'success';
    }


    public function ajax_edit_reply_info()
    {
        $respnse = array();
        $table_id = $this->input->post('table_id');
        $info = $this->basic->get_data('facebook_ex_autoreply',array('where'=>array('id'=>$table_id)));

        if($info[0]['reply_type'] == 'generic'){
            $reply_content = json_decode($info[0]['auto_reply_text']);
            if(!is_array($reply_content))
            {
                $reply_content[0]['comment_reply'] = "";
                $reply_content[0]['private_reply'] = $info[0]['auto_reply_text'];
            }
        }
        else
            $reply_content = json_decode($info[0]['auto_reply_text']);

        $nofilter_word_text = json_decode($info[0]['nofilter_word_found_text']);
        if(!is_array($nofilter_word_text))
        {
            $nofilter_word_text[0]['comment_reply'] = '';
            $nofilter_word_text[0]['private_reply'] = $info[0]['nofilter_word_found_text'];
        }

        $respnse['reply_type'] = $info[0]['reply_type'];
        $respnse['comment_reply_enabled'] = $info[0]['comment_reply_enabled'];
        $respnse['multiple_reply'] = $info[0]['multiple_reply'];
        $respnse['auto_like_comment'] = $info[0]['auto_like_comment'];
        $respnse['auto_reply_text'] = $reply_content;
        $respnse['edit_auto_reply_page_id'] = $info[0]['page_info_table_id'];
        $respnse['edit_auto_reply_post_id'] = $info[0]['post_id'];
        $respnse['edit_auto_campaign_name'] = $info[0]['auto_reply_campaign_name'];
        $respnse['edit_nofilter_word_found_text'] = $nofilter_word_text;

        echo json_encode($respnse);
    }


    public function ajax_update_autoreply_submit()
    {
        if($_POST)
        {
            $post=$_POST;
            foreach ($post as $key => $value) 
            {
                $$key=$value;
            }
        }

        $return = array();

        if($edit_message_type == 'generic')
        {
            // $auto_reply_text = $edit_generic_message;

            $generic_message_array['comment_reply'] = trim($edit_generic_message);
            $generic_message_array['private_reply'] = trim($edit_generic_message_private);
            $generic_array = array();
            array_push($generic_array, $generic_message_array);
            $auto_reply_text = json_encode($generic_array);
            // $auto_reply_text = $this->db->escape($generic_message_text);
        }
        else
        {
            $auto_reply_text_array = array();
            for($i=1;$i<=10;$i++)
            {
                $filter_word = 'edit_filter_word_'.$i;
                $filter_word_text = $this->input->post($filter_word);
                $filter_message = 'edit_filter_message_'.$i;
                $filter_message_text = $this->input->post($filter_message);

                // added 25-04-2017
                $comment_message = 'edit_comment_reply_msg_'.$i;
                $comment_message_text = $this->input->post($comment_message);

                if($filter_word_text != '' && ($filter_message_text != '' || $comment_message_text != ''))
                {
                    // $auto_reply_text_array[$filter_word_text] = $this->db->escape($filter_message_text);
                    $data['filter_word'] = trim($filter_word_text);
                    $data['reply_text'] = trim($filter_message_text);
                    $data['comment_reply_text'] = trim($comment_message_text);
                    array_push($auto_reply_text_array, $data);
                }
            }
            $auto_reply_text = json_encode($auto_reply_text_array);
        }

        $no_filter_array['comment_reply'] = trim($edit_nofilter_word_found_text);
        $no_filter_array['private_reply'] = trim($edit_nofilter_word_found_text_private);
        $nofilter_array = array();
        array_push($nofilter_array, $no_filter_array);

        $data = array(
            'auto_reply_text' => $auto_reply_text,
            'reply_type' => $edit_message_type,
            'auto_reply_campaign_name' => $edit_auto_campaign_name,
            'nofilter_word_found_text' => json_encode($nofilter_array),
            'comment_reply_enabled' => $edit_comment_reply_enabled,
            'multiple_reply' => $edit_multiple_reply,
            'auto_like_comment' => $edit_auto_like_comment
            );
        $where = array(
            'user_id' => $this->user_id,
            'page_info_table_id' => $edit_auto_reply_page_id,
            'post_id' => $edit_auto_reply_post_id
            );

        if($this->basic->update_data('facebook_ex_autoreply',$where,$data))
        {
            $return['status'] = 1;
            $return['message'] = "<div class='alert alert-success'>Your given information has been updated successfully.</div>";
        }
        else
        {
            $return['status'] = 0;
            $return['message'] = "<div class='alert alert-danger'>Something went wrong, please try again.</div>";
        }
        echo json_encode($return);
    }


    public function auto_reply_report($page_info_table_id=0)
    {
        if($page_info_table_id==0) exit();
        $page_info = $this->basic->get_data('facebook_ex_autoreply',array('where'=>array('page_info_table_id'=>$page_info_table_id,'user_id'=>$this->user_id)),'','',1);
        $data['page_name'] = isset($page_info[0]['page_name']) ? $page_info[0]['page_name']:'';

        $data['body'] = 'facebook_ex/auto_reply/auto_reply_report';
        $data['page_title'] = $this->lang->line('Auto reply - Report');
        $data['page_table_id'] = $page_info_table_id;
        $data['emotion_list'] = $this->get_emotion_list();
        $this->_viewcontroller($data);
    }


    public function auto_reply_report_data($table_id=0)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET')
        redirect('home/access_forbidden', 'location');

        $page = isset($_POST['page']) ? intval($_POST['page']) : 15;
        $rows = isset($_POST['rows']) ? intval($_POST['rows']) : 5;
        $sort = isset($_POST['sort']) ? strval($_POST['sort']) : 'id';
        $order = isset($_POST['order']) ? strval($_POST['order']) : 'ASC';

        $campaign_name = trim($this->input->post("campaign_name", true));
        $is_searched = $this->input->post('is_searched', true);

        if ($is_searched) 
        {
            $this->session->set_userdata('search_post_campaign_name', $campaign_name);
        }

        // saving session data to different search parameter variables
        $search_campaign_name   = $this->session->userdata('search_post_campaign_name');

        $where_simple=array();        
        if ($search_campaign_name) $where_simple['auto_reply_campaign_name like '] = "%".$search_campaign_name."%";

        $where_simple['page_info_table_id'] = $table_id;
        $where_simple['user_id'] = $this->user_id;

        $where  = array('where'=>$where_simple);
        $order_by_str=$sort." ".$order;
        $offset = ($page-1)*$rows;
        $result = array();
        $table = "facebook_ex_autoreply";
        // $select = array('id','auto_reply_campaign_name','post_created_at','last_updated_at');
        $info = $this->basic->get_data($table, $where, $select='', $join='', $limit=$rows, $start=$offset, $order_by=$order_by_str, $group_by='');

        $total_rows_array = $this->basic->count_row($table, $where, $count="id", $join='');
        $total_result = $total_rows_array[0]['total_rows'];

        echo convert_to_grid_data($info, $total_result);
    }


    public function ajax_get_reply_info()
    {
        $table_id = $this->input->post('table_id');
        $reply_info = $this->basic->get_data('facebook_ex_autoreply',array('where'=>array('id'=>$table_id)));

        if(isset($reply_info[0]['auto_reply_done_info']) && $reply_info[0]['auto_reply_done_info'] != '')
        {
            $str = '<script>
                    $j(document).ready(function() {
                        $("#campaign_report").DataTable();
                    }); 
                 </script>
                 <table id="campaign_report">
                     <thead>
                         <tr>
                             <th>Name</th>
                             <th>Comment</th>
                             <th>Comment Time</th>
                             <th>Private Reply Message</th>
                             <th>Comment Reply Message</th>
                             <th>Reply Time</th>
                             <th>Private Reply Status</th>
                             <th>Comment Reply Status</th>
                         </tr>
                     </thead>
                     <tbody>';
                         
                     
            $info = json_decode($reply_info[0]['auto_reply_done_info'],'true');
            foreach($info as $value)
            {
                $comment_time = date('Y-m-d H:i:s',strtotime($value['comment_time']));
                $comment_status = isset($value['reply_status_comment']) ? $value['reply_status_comment']:"";
                $str .= '<tr>
                            <td>'.$value['name'].'</td>
                            <td>'.$value['comment_text'].'</td>
                            <td>'.$comment_time.'</td>
                            <td>'.$value['reply_text'].'</td>
                            <td>'.$value['comment_reply_text'].'</td>
                            <td>'.$value['reply_time'].'</td>
                            <td>'.$value['reply_status'].'</td>
                            <td>'.$comment_status.'</td>
                        </tr>';
            }

            $str .= '</tbody>
                 </table>';
        }
        else
        {
            $str = "<div class='alert alert-danger'>No data to show</div>";
        }

        echo $str;
    }


    public function all_auto_reply_report()
    {
        $page_info = array();
        $page_list = $this->basic->get_data("facebook_rx_fb_page_info",array("where"=>array("user_id"=>$this->user_id,"facebook_rx_fb_user_info_id"=>$this->session->userdata("facebook_rx_fb_user_info"))));
        if(!empty($page_list))
        {
            foreach($page_list as $value)
            {
                array_push($page_info, $value['page_name']);
            }
        }
        $data['page_info'] = $page_info;

        $data['body'] = 'facebook_ex/auto_reply/all_auto_reply_report';
        $data['page_title'] = $this->lang->line('Auto reply - Report');
        $data['emotion_list'] = $this->get_emotion_list();
        $this->_viewcontroller($data);
    }


    public function all_auto_reply_report_data()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET')
        redirect('home/access_forbidden', 'location');

        $page = isset($_POST['page']) ? intval($_POST['page']) : 15;
        $rows = isset($_POST['rows']) ? intval($_POST['rows']) : 5;
        $sort = isset($_POST['sort']) ? strval($_POST['sort']) : 'id';
        $order = isset($_POST['order']) ? strval($_POST['order']) : 'ASC';

        $campaign_name = trim($this->input->post("campaign_name", true));
        $search_page_name = trim($this->input->post("search_page_name", true));
        $is_searched = $this->input->post('is_searched', true);

        if ($is_searched) 
        {
            $this->session->set_userdata('all_search_post_campaign_name', $campaign_name);
            $this->session->set_userdata('all_search_page_name', $search_page_name);
        }

        // saving session data to different search parameter variables
        $search_campaign_name   = $this->session->userdata('all_search_post_campaign_name');
        $all_search_page_name   = $this->session->userdata('all_search_page_name');

        $where_simple=array();        
        if ($search_campaign_name) $where_simple['auto_reply_campaign_name like '] = "%".$search_campaign_name."%";
        if ($all_search_page_name != '') $where_simple['page_name like '] = "%".$all_search_page_name."%";

        // $where_simple['page_info_table_id'] = $table_id;
        $where_simple['user_id'] = $this->user_id;

        $where  = array('where'=>$where_simple);
        $order_by_str=$sort." ".$order;
        $offset = ($page-1)*$rows;
        $result = array();
        $table = "facebook_ex_autoreply";
        // $select = array('id','auto_reply_campaign_name','post_created_at','last_updated_at');
        $info = $this->basic->get_data($table, $where, $select='', $join='', $limit=$rows, $start=$offset, $order_by=$order_by_str, $group_by='');

        $total_rows_array = $this->basic->count_row($table, $where, $count="id", $join='');
        $total_result = $total_rows_array[0]['total_rows'];

        echo convert_to_grid_data($info, $total_result);
    }


}