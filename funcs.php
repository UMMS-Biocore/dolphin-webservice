<?php

class funcs {
   private $dbhost       = "";
   private $db           = "";
   private $dbuser       = "";
   private $dbpass       = "";
   private $tool_path    = "";
   private $python       = "python";
 
   function readINI()
   {
     if ($this->dbhost=="")
     { 
        $param_section = "Default";
        
        if (!empty(getenv('DOLPHIN_PARAMS_SECTION'))){
           $param_section=getenv('DOLPHIN_PARAMS_SECTION');
        }               
        $ini = parse_ini_file("config.ini", true);
        $ini_array = $ini[$param_section];
        $this->dbhost=$ini_array['DB_HOST'];
        $this->db=$ini_array['DB_NAME'];
        $this->dbpass=$ini_array['DB_PASSWORD'];
        $this->dbuser=$ini_array['DB_USER'];
        $this->tool_path=$ini_array['DOLPHIN_TOOLS_SRC_PATH'];
     }
   }
 
   function getKey()
   {
        $characters = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $wkey = '';
        $ret  = '';
        for ($i = 0; $i < 30; $i++) {
            $wkey .= $characters[rand(0, strlen($characters))];
        }
        # If this random key exist it randomize another key
        if($this->getWorkflowId($wkey))
            $ret=$this->getKey();
        else
            $ret=$wkey;
     return $ret;
   }
   function runSQL($sql)
   {
        $this->readINI();
	$link = new mysqli($this->dbhost, $this->dbuser, $this->dbpass, $this->db);
	// check connection
	if (mysqli_connect_errno()) { 
  		exit('Connect failed: '. mysqli_connect_error());
	}
        $i=0;
	while($i<3)
        {
	  $result=$link->query($sql);

	  if ($result) 
	  {
	        $link->close();
	 	return $result;
	  }
          sleep(5*($i+1)); 
	  $i++;
        }
	$link->close();
	return $sql;
   }
   function syscall($command)
   {
    $result="";
    if ($proc = popen("($command)2>&1","r")){
        while (!feof($proc)) $result .= fgets($proc, 1000);
        pclose($proc);
        return $result; 
     }
     else
     {
        return "ERROR 104: Cannot run $command!";
     }
        
   }
   #docker: wkey added, checkJobInCluster totally changed
   function checkJobInCluster($wkey, $job_num, $username)
   {
      #$com="ssh -o ConnectTimeout=30 $username@".$this->remotehost." \"".$this->bjobs." $job_num\"|grep ".$job_num."|awk '{print \$5\"\\t\"\$1}'";
      $com="ps -ef|grep \"[[:space:]]".$job_num."[[:space:]]\"|awk '{print \$8\"\\t\"\$1}'";

      $retval=$this->syscall($com);
      while(eregi("is not found", $retval))
      {
         $retval=$this->syscall($com);
      }
      if ($retval==""){
        $ret = $this->checkJobInDB($wkey, $job_num, $username);
	if ($ret == 0) { 
           $retval="EXIT"; 
        }else{
	   $retval="DONE";
        }
      }
      return $retval;
   }
   function checkJobInDB($wkey, $job_num, $username)
   {
      sleep(5);
      $sql="select * from jobs j where wkey='$wkey' and job_num='$job_num' and result=3 and username='$username'";
      $res = $this->runSQL($sql);
      $num_rows =$res->num_rows;   
      #Check if there are jobs which are failed or running
      if ($num_rows > 0 )
      {
         return "Job Finsihed Sucessfully!!!"; 
      }
      return 0;
   }
   
   function checkStatus($servicename, $wkey)
   {
     $sql="select * from jobs j, services s where s.service_id=j.service_id and s.servicename='$servicename' and j.wkey='$wkey'";
        #return $sql;
     $res = $this->runSQL($sql);
     $num_rows =$res->num_rows;   
     if (is_object($res) && $num_rows>0)
     {
        $rowser=$res->fetch_assoc();
        $service_id=$rowser['service_id'];
        $sql="select DISTINCT j.job_num job_num, j.jobname jobname, j.jobstatus jobstatus, j.result jresult, s.username username from jobs j, services s where s.service_id=j.service_id and s.servicename='$servicename' and wkey='$wkey' and jobstatus=1 and result<3";
        
        $res = $this->runSQL($sql);
        $num_rows =$res->num_rows;   
        #Check if there are jobs which are failed or running
        if (is_object($res) && $num_rows > 0 )
        {
           while($row = $res->fetch_assoc())
           {
             # If job is running, it turns 1 otherwise 0 and it needs to be restarted
             # If it doesn't turn Error and if job is working it turns wkey to che
             $retval=$this->checkJobInCluster($wkey, $row['job_num'], $row['username']);
             #return $retval;
	     /*if ($retval==0)
             {
               if ($ret=$this->checkJobInDB($wkey, $row['job_num'], $row['username']))
               {
                 return $ret;
               }
                
             }*/
             if(eregi("^EXIT", $retval))
             {
               $sql="SELECT j.jobname, jo.jobout FROM biocore.jobs j, biocore.jobsout jo where j.wkey=jo.wkey and j.job_num=jo.jobnum and j.job_num=".$row['job_num']." and jo.wkey='$wkey'";
               $resout = $this->runSQL($sql);
               $rowout=$resout->fetch_assoc();
               require_once('class.html2text.inc');

               $h2t =& new html2text($rowout['jobout']);
               $jobout=$h2t->get_text();
               return 'ERROR:'.$retval."\n".$rowout['jobname']." Failed\nCheck LSF output\n". $jobout;
             }
             if(eregi("DONE", $retval))
             {
               $jn=rtrim(substr($retval, 5));
               $sql = "select * from biocore.jobs where result=3 and job_num='".$jn."' and wkey='$wkey'";
               $result = $this->runSQL($sql);
               if (is_object($result) )
               { 
                 $sql = "UPDATE biocore.jobs set result='3', end_time=now() where job_num='".$jn."' and wkey='$wkey'"; 
                 $result = $this->runSQL($sql);
               }
               else
               {
                  $sql = "insert into jobs(`username`, `wkey`, `jobname`, `service_id`, `result`, `submit_time`, `start_time`,`end_time`,`job_num`) values ('".$row['username']."', '$wkey', '$servicename', '$service_id', '3', now(), now(), now(),  '$jn' )";

                  $result = $this->runSQL($sql);

               }
             } 
           }
         }
         else
         {
	     $sql="select * from service_run sr, services s where s.service_id=sr.service_id and s.servicename='$servicename' and sr.wkey='$wkey' and sr.result=1";
             $res = $this->runSQL($sql);
             $num_rows =$res->num_rows;
	     if (is_object($res) && $num_rows > 0 )
             {
                return "Service ended successfully!!!";
	     }
         }
         return "RUNNING(1):$retval:SERVICENAME:$servicename:[".$this->dbhost."]";
         #return "RUNNING(1):SERVICENAME:$servicename";
         #return "RUNNING";
      }
      return 'START';
   }

   
   function getServiceOrder($workflow_id, $service_id, $wkey)
   {
      $sql = "select service_order from workflow_services where workflow_id=$workflow_id and service_id=$service_id";
      $result = $this->runSQL($sql);
      if (is_object($result) && $row = $result->fetch_row())
         return -1;
              
      $sql = "select max(service_order) from workflow_services w, workflow_run wr where wr.workflow_id=$workflow_id and wr.workflow_id=w.workflow_id and wr.wkey='$wkey'";
      $result = $this->runSQL($sql);
      if (is_object($result) && $row = $result->fetch_row())
      {
          $id=$row[0]+1;
      }
      else
      {
          $id=1;
      }
        
        return ($id);
   }
   
   function getWorkflowId($wkey)
   {
        $sql = "select workflow_id from workflow_run where wkey='$wkey'";
        $result = $this->runSQL($sql);
        if (is_object($result) && $row = $result->fetch_row())
        {
           $id=$row[0];
        }
	else
	{
	   return 0;
	}
        return $id;
   }
   
   function getId($name, $username, $val, $wkey, $defaultparam)
   {
        $sql = "select ".$name."_id from ".$name."s where `".$name."name`='$val' and username='$username'";
        $result = $this->runSQL($sql);
        if (is_object($result) && $row = $result->fetch_row())
        {
           $id=$row[0];
	   if ($name=="workflow")
           {
	     $sql = "update ".$name."_id from ".$name."s where `".$name."name`='$val' and username='$username'";
	   } 
        }
        else
        {
            #If workflow and service doesn't exist. This registers those workflows automatically. 

            $sql = "insert into ".$name."s(`".$name."name`, `description`, `username`, `defaultparam`) values('".$val."', 'Service description', '$username', '$defaultparam')";
            $result = $this->runSQL($sql);
            $id=$this->getId($name, $username, $val, $key, $defaultparam);
        }
        
        if ($name=="service")
        {
            $workflow_id = $this->getWorkflowId($wkey);
            $service_order = $this->getServiceOrder($workflow_id, $id, $wkey);  
            if($service_order>0 && $workflow_id>0)
            {
                $sql = "insert into workflow_services(`workflow_id`, `service_id`, `service_order`) values($workflow_id,$id, $service_order)";
                $result = $this->runSQL($sql);
            }   
        }
        return $id;
   }

   function getWorkflowInformation($wkey)
   {
        $sql = "select wr.username, wr.inputparam, wr.outdir, w.defaultparam from workflow_run wr, workflows w  where w.workflow_id=wr.workflow_id and wr.wkey='$wkey'";
        $result = $this->runSQL($sql);
        if (is_object($result) && $row = $result->fetch_row())
        {
           return $row;
        }
        return "ERROR 001: in getWorkflowInformation";
   }
   function updateInputParam($wkey, $username, $inputparam)
   {
        $sql = "select inputparam from workflow_run where wkey='$wkey' and username='$username'";
        
        $result = $this->runSQL($sql);
        if (is_object($result) && $row = $result->fetch_row())
        {
         
            if ($inputparam!=$row[0])
            {

                $sql = "update workflow_run set inputparam='".$inputparam."' where wkey='$wkey' and username='$username'";
                $result = $this->runSQL($sql);   
            }
            return $inputparam;
        }
        return "ERROR 002: in getWorkflowInput";
   }

   function updateDefaultParam($workflowname, $username, $defaultparam)
   {

      $sql = "update workflows set defaultparam='".$defaultparam."' where username='$username' and workflowname='$workflowname'";
      $result = $this->runSQL($sql);   

   }
   
   function getCommand($servicename, $username, $inputcommand, $defaultparam)
   {
        $sql = "select command, defaultparam from services where servicename='$servicename' and username='$username'";
        
        $result = $this->runSQL($sql);
        if (is_object($result) && $row = $result->fetch_row())
        {
            if ($inputcommand!=$row[0] || $defaultparam!=$row[1])
            {
                $sql = "update services set command='".$inputcommand."', defaultparam='".$defaultparam."' where servicename='$servicename' and username='$username'";
                $result = $this->runSQL($sql);   
            }
            return $inputcommand;
        }
        return "ERROR 003: in getServiceCommand";
   }

   function startWorkflow($inputparam, $defaultparam, $username, $workflowname, $wkey, $status, $outdir, $services)
   {
      
      if ($status=="new")
      {
        #return "inputparam:$inputparam, defaultparam:$defaultparam, username:$username, workflowname:$workflowname, wkey:$wkey, outdir:$outdir";
        $workflow_id=$this->getId("workflow", $username, $workflowname, $wkey, $defaultparam);
        // sql query for INSERT INTO workflowrun
        $sql = "INSERT INTO `workflow_run` ( `workflow_id`, `username`, `wkey`, `inputparam`, `outdir`, `result`, `start_time`, `services`) VALUES ('$workflow_id', '$username', '$wkey', '$inputparam', '$outdir', '0', now(), $services)";
        $this->updateDefaultParam($workflowname, $username, $defaultparam);
	if($result=$this->runSQL($sql))
        {
            return 1;
        }
	
      }
      else
      {
          $inputparam=$this->updateInputParam($wkey, $username, $inputparam);
	  
          return $inputparam;
      }
      return 0; 
   }

   function startService($servicename, $wkey, $inputcommand)
   {
        $this->readINI();
               
        $wf = $this->getWorkflowInformation($wkey);
        if (is_array($wf))
        {
          $username     = $wf[0];
          $inputparam   = $wf[1];
          $outdir       = $wf[2];
          $defaultparam = $wf[3];

          $service_id=$this->getId("service", $username, $servicename, $wkey, $defaultparam);

          $sql = "SELECT service_id FROM biocore.service_run where wkey='$wkey' and service_id='$service_id';";
          $res = $this->runSQL($sql);
	  if (empty($res)=="")
          {

	    // sql query for INSERT INTO service_run
	    $sql = "INSERT INTO `service_run` (`service_id`, `wkey`, `input`,`result`, `start_time`) VALUES ('$service_id', '$wkey', '', '0', now())"; 
        
            #return "$username:$password<BR>";
        
            if($result=$this->runSQL($sql))
	    {	
  
		### RUN THE JOB HERE AND UPDATE THE RESULT 1 WHEN IT IS FINISHED
                $command=$this->getCommand($servicename, $username, $inputcommand, $defaultparam);

                $ipf="";
                if ($inputparam != "") 
                   #$ipf="-i \\\"$inputparam\\\"";
		   #docker:extra \ removed to run it without submitting
                   $ipf="-i \"$inputparam\"";
                $dpf="";
                if ($defaultparam != "") 
                   $dpf="-p $defaultparam";

		#docker: job runService command changed
                $edir=$this->tool_path;
                #$com="ssh -o ConnectTimeout=30 $username@".$this->remotehost." \"".$this->python." ".$edir."/src/runService.py  -d ".$this->dbhost." $ipf $dpf -o $outdir -u $username -k $wkey -c \\\"$command\\\" -n $servicename -s $servicename\" 2>&1";
                $com=$this->python." ".$edir."/runService.py  -d ".$this->dbhost." $ipf $dpf -o $outdir -u $username -k $wkey -c \"$command\" -n $servicename -s $servicename 2>&1";
                $retval=system($com);
                #return $com;
                if(eregi("Error", $retval))
                {
                   return "ERROR: $retval";
                }
                #return $com;
                return "RUNNING(2):$com";
                #return "RUNNING(2)";
                #return "RUNNING";
	    }
          }
        }
        else
        {
           return $wf;
        }
   }

   function checkLastServiceJobs($wkey)
   {
      $sql = "SELECT username, job_num from jobs where service_id=(SELECT service_id FROM biocore.service_run where wkey='$wkey' order by service_run_id desc limit 1)  and wkey='$wkey' and jobstatus=1;";
      $result = $this->runSQL($sql);
      #Get how many jobs hasn't finished
      $ret=1;
      return $ret;
      if (is_object($result))
      {
	 while($row = $result->fetch_row())
         {
	    $username=$row[0];
	    $jobnum=$row[1];
	    $retval=$this->checkJobInCluster($wkey, $jobnum, $username);
	    if(eregi("^EXIT", $retval)) 
            {
	      $ret=0;
	    }
	 }
      }
      return $ret;
      
   }
   function endWorkflow($wkey)
   {
        $sql = "update workflow_run set result='1', end_time=now() where wkey='$wkey'";
        $result = $this->runSQL($sql);
	$sql = "update ngs_runparams set run_status='1' where wkey='$wkey'";
        $result = $this->runSQL($sql);   
		   #return $sql;
        return "Success!!!";
        $sql1 = "SELECT sum(w.result) from (SELECT result from workflow_services ws left join service_run s on ws.service_id=s.service_id where ws.workflow_id=(SELECT workflow_id FROM biocore.workflow_run wr where wkey='$wkey') and wkey='$wkey') w";
        $result1 = $this->runSQL($sql1);
	#Get how many service successfuly finished
        if (is_object($result1) && $row1 = $result1->fetch_row())
        {
	    #Get how many services exist in the workflow
            $sql2 = "SELECT count(*) from workflow_services ws where workflow_id=(SELECT workflow_id FROM biocore.workflow_run wr where wkey='$wkey')";
            $result2 = $this->runSQL($sql2);
            if (is_object($result2) && $row2 = $result2->fetch_row())
            {
 
                if ($row1[0]>=$row2[0])
                {
                   $sql = "update workflow_run set result='1', end_time=now() where wkey='$wkey'";
                   $result = $this->runSQL($sql);   
		   #return $sql;
                   return "Success!!!";
                }
		else
		{
		   # if non of the last service jobs are running in the cluster.
		   # exit and give an error
		   if (!$this->checkLastServiceJobs($wkey))
		   {
		      return "ERROR: Workflow couldn't sucessfully completed. Please check the results!!!\n";
		   }
		}
            }
	    
        }
        #return "$sql1 :::: $sql2";
        return "WRUNNING";
   
   }
  #Insert a job to the database
   function insertJob($username, $wkey , $com , $jobname, $servicename , $jobnum, $result)
   {
       $workflow_id = $this->getWorkflowId($wkey);
       $service_id  = $this->getId("service", $username, $servicename, $wkey, "");

       $sql = "insert into jobs(`username`, `wkey`, `run_script`, `jobname`, `workflow_id`, `service_id`, `result`, `submit_time`, `job_num`) values ('$username','$wkey','$com','$jobname','$workflow_id','$service_id', '$result', now(), '$jobnum')";

       $res = $this->runSQL($sql);

       return $res;
   }

   #Update a job to the database
   function updateJob($username, $wkey , $jobname, $servicename, $field , $jobnum, $result)
   {
       $workflow_id=$this->getWorkflowId($wkey);
       $service_id=$this->getId("service", $username, $servicename, $wkey, "");

       #$sql = "update jobs set `$field`=now(), `result`='$result' where `username`= '$username' and `wkey`='$wkey' and `jobname`='$jobname' and `workflow_id`='$workflow_id' and `service_id`='$service_id' and `job_num`='$jobnum'";
       $sql = "update jobs set `$field`=now(), `result`='$result' where `wkey`='$wkey' and `job_num`='$jobnum'";
       #$sql1 = "select job_id, submit_time, start_time, end_time from jobs where `wkey`='$wkey' and `job_num`='$jobnum'";
       $res = $this->runSQL($sql);
       return $res.":".$sql;
   }
   
   #Check if all jobs are finished or not for a service
   function checkAllJobsFinished($username, $wkey, $servicename)
   {
      $workflow_id=$this->getWorkflowId($wkey);
      $service_id=$this->getId("service", $username, $servicename, $wkey, "");
      $select  = "select count(job_id) c from jobs ";
      $where1 = " where `username`= '$username' and `wkey`='$wkey' and `workflow_id`='$workflow_id' and `service_id`='$service_id'";
      $where2 = " and `result`=3 ";
      $sql = "select s1.c, s2.c from ( $select  $where1) s1,  ($select  $where1 $where2) s2";
      $result = $this->runSQL($sql);
      #Get how many service successfuly finished
      if (is_object($result) && $row = $result->fetch_row())
      {
        $s1     = $row[0];
        $s2     = $row[1];
 
        if($s1==$s2){
            $res=$this->updateService($wkey, $service_id, 1);
        }
        else
        { 
            $res="Should be still running 1";
        }
      }
      else
      {
         $res="Should be still running 2";
      }
      return $res;
   }
   function updateService($wkey, $service_id, $result)
   {
      $sql = "update service_run set `end_time`=now(), `result`='$result' where `wkey`='$wkey' and `service_id`='$service_id'"; 
      $res = $this->runSQL($sql);

      return $res;
   }
   #Insert a job output to the database
   function insertJobOut($username, $wkey , $jobnum, $jobout)
   {
       
       $sql = "insert into jobsout(`username`, `wkey`, `jobnum`, `jobout`) values ('$username','$wkey','$jobnum','$jobout')";
       $res = $this->runSQL($sql);
       
       return $res;
   }
}
?>
