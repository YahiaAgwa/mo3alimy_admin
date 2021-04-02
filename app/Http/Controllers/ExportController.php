<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\User;
use PHPExcel;
use PHPExcel_IOFactory;

class ExportController extends Controller
{
     public function submit(Request $request,$table){
        $user_id = 0;
        $order_num = 0;
        $used = "all";
        $key = "";
        $filter = "";
        $s = "";
        $url = $_SERVER['HTTP_REFERER'];
        $array = explode("?",$url);
        if(count($array)>1){
            $array2 = explode("&",$array[1]);
            if(count($array2)>1){
                if(isset($array2[0])){
                    $array4 = explode("=",$array2[0]);
                    if(count($array4)>1){if($array4[0]=="user_id") $user_id = $array4[1]; else if($array4[0]=="order_num") $order_num = $array4[1]; else if($array4[0]=="key") $key = $array4[1]; else if($array4[0]=="used") $used = $array4[1];}      
                }
                if(isset($array2[1])){
                    $array4I = explode("=",$array2[1]);
                    if(count($array4I)>1){if($array4I[0]=="order_num") $order_num = $array4I[1]; else if($array4I[0]=="user_id") $user_id = $array4I[1]; else if($array4I[0]=="filter") $filter = $array4I[1]; else if($array4I[0]=="used") $used = $array4I[1];}
                }
                if(isset($array2[2])){
                    $array4II = explode("=",$array2[2]);
                    if(count($array4II)>1){if($array4II[0]=="s") $s = $array4II[1]; elseif($array4II[0]=="order_num") $order_num = $array4II[1]; elseif($array4II[0]=="user_id") $user_id = $array4II[1]; elseif($array4II[0]=="used") $used = $array4II[1]; }
                }
                
            }
        }
        // dd($user_id,$order_num,$key,$s,$used);
        // dd($request->all(),$url);
        ///////////////////////////////
        $objPHPExcel = new PHPExcel();
        $objPHPExcel->setActiveSheetIndex(0);

        $styleArray = array(
            'font' => array(
                'bold' => true,
                'color' => array('rgb' => 'FF0000'),
                'size' => 15,
                'name' => 'Verdana'
            ));

            if($table == "codes"){
                $data = DB::table("codes")
                ->where(function($q) use ( $order_num , $user_id , $key , $filter , $s ,$used)
                {
                    if ($order_num != null && !empty($order_num)) {
                        $q->where("order_num",$order_num);
                    }
                    if ($user_id != null && !empty($user_id)) {
                        $q->where("user_id",$user_id);
                    }
                    if($used !="all" && $used!= null){
                        $q->where("used", $used);
                    }
                    if ($key != null && !empty($key) && $s != null && !empty($s)) {
                        if($filter == "contains"){
                            $q->where($key,"like", '%' .$s . '%' );
                        }else{
                            $q->where($key,$s);
                        }
                        
                    }
                })->orderBy("id", "desc")->get();
            }else{
                $data = DB::table($table)->orderBy("id", "desc")->get();
            }
        
        ////////////////
        $cols = \Schema::getColumnListing($table);
        $rowCount = 1;
        $column = 'A';

        foreach ($cols as $s) {
            $objPHPExcel->getActiveSheet()->getColumnDimension($column)->setWidth(30);
            $objPHPExcel->getActiveSheet()->setCellValue($column . $rowCount, $s);
            $column++;
            if($s == "user_id"){
                $objPHPExcel->getActiveSheet()->getColumnDimension($column)->setWidth(30);
                $objPHPExcel->getActiveSheet()->setCellValue($column . $rowCount, "user_name");
                $column++;
            }
        }
        $rowCount = 2;

        foreach ($data as $st) {
            $column = 'A';

            foreach ($cols as $s) {
                $objPHPExcel->getActiveSheet()->setCellValue($column . $rowCount, $st->$s);
                $objPHPExcel->getActiveSheet()->getColumnDimension($column)->setWidth(30);
                $column++;
                if($s == "user_id"){
                    $one = User::find($st->user_id);
                    if($one){
                        $objPHPExcel->getActiveSheet()->setCellValue($column . $rowCount, $one->name);
                        $objPHPExcel->getActiveSheet()->getColumnDimension($column)->setWidth(30);
                    }
                    $column++;
                }
            }
            $rowCount++;
        }


        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment;filename='.$table.'.xls');
        header('Cache-Control: max-age=0');
        $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
        $objWriter->save('php://output');

    
    }
}
