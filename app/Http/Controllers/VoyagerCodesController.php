<?php

namespace App\Http\Controllers;
use DB;
use File;
use Auth;
use Hash;
use Response;
use Carbon\Carbon;

use Illuminate\Http\Request;
use App\User;
use App\Code;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use Validator;
use Illuminate\Support\Str;
use TCG\Voyager\Database\Schema\SchemaManager;
use TCG\Voyager\Events\BreadDataAdded;
use TCG\Voyager\Events\BreadDataDeleted;
use TCG\Voyager\Events\BreadDataUpdated;
use TCG\Voyager\Events\BreadImagesDeleted;
use TCG\Voyager\Facades\Voyager;
use TCG\Voyager\Http\Controllers\Traits\BreadRelationshipParser;

class VoyagerCodesController extends \TCG\Voyager\Http\Controllers\VoyagerBaseController
{
    
    public function index(Request $request)
    {
        // dd($request->all(),Auth::user());
        // GET THE SLUG, ex. 'posts', 'pages', etc.
        $slug = $this->getSlug($request);

        // GET THE DataType based on the slug
        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Check permission
        $this->authorize('browse', app($dataType->model_name));

        $getter = $dataType->server_side ? 'paginate' : 'get';

        $search = (object) ['value' => $request->get('s'), 'key' => $request->get('key'), 'filter' => $request->get('filter')];

        $searchNames = [];
        if ($dataType->server_side) {
            $searchable = SchemaManager::describeTable(app($dataType->model_name)->getTable())->pluck('name')->toArray();
            $dataRow = Voyager::model('DataRow')->whereDataTypeId($dataType->id)->get();
            foreach ($searchable as $key => $value) {
                $field = $dataRow->where('field', $value)->first();
                $displayName = ucwords(str_replace('_', ' ', $value));
                if ($field !== null) {
                    $displayName = $field->getTranslatedAttribute('display_name');
                }
                $searchNames[$value] = $displayName;
            }
        }

        $orderBy = $request->get('order_by', $dataType->order_column);
        $sortOrder = $request->get('sort_order', $dataType->order_direction);
        $usesSoftDeletes = false;
        $showSoftDeleted = false;

        // Next Get or Paginate the actual content from the MODEL that corresponds to the slug DataType
        if (strlen($dataType->model_name) != 0) {
            $model = app($dataType->model_name);

            if ($dataType->scope && $dataType->scope != '' && method_exists($model, 'scope'.ucfirst($dataType->scope))) {
                $query = $model->{$dataType->scope}();
            } else {
                $query = $model::select('*');
            }

            // Use withTrashed() if model uses SoftDeletes and if toggle is selected
            if ($model && in_array(SoftDeletes::class, class_uses_recursive($model)) && Auth::user()->can('delete', app($dataType->model_name))) {
                $usesSoftDeletes = true;

                if ($request->get('showSoftDeleted')) {
                    $showSoftDeleted = true;
                    $query = $query->withTrashed();
                }
            }

            // If a column has a relationship associated with it, we do not want to show that field
            $this->removeRelationshipField($dataType, 'browse');

            if ($search->value != '' && $search->key && $search->filter) {
                $search_filter = ($search->filter == 'equals') ? '=' : 'LIKE';
                $search_value = ($search->filter == 'equals') ? $search->value : '%'.$search->value.'%';
                $query->where($search->key, $search_filter, $search_value);
            }
            if(isset($request->user_id) && !empty($request->user_id) && $request->user_id!= null){
                $query->where("user_id", $request->user_id);
            }
            if(isset($request->order_num) && !empty($request->order_num) && $request->order_num!= null){
                $query->where("order_num", $request->order_num);
            }
            if(isset($request->used) && $request->used !="all" && $request->used!= null){
                $query->where("used", $request->used);
            }

            if ($orderBy && in_array($orderBy, $dataType->fields())) {
                $querySortOrder = (!empty($sortOrder)) ? $sortOrder : 'desc';
                $dataTypeContent = call_user_func([
                    $query->orderBy($orderBy, $querySortOrder),
                    $getter,
                ]);
            } elseif ($model->timestamps) {
                $dataTypeContent = call_user_func([$query->latest($model::CREATED_AT), $getter]);
            } else {
                $dataTypeContent = call_user_func([$query->orderBy($model->getKeyName(), 'DESC'), $getter]);
            }

            // Replace relationships' keys for labels and create READ links if a slug is provided.
            $dataTypeContent = $this->resolveRelations($dataTypeContent, $dataType);
        } else {
            // If Model doesn't exist, get data from table name
            $dataTypeContent = call_user_func([DB::table($dataType->name), $getter]);
            $model = false;
        }

        // Check if BREAD is Translatable
        $isModelTranslatable = is_bread_translatable($model);

        // Eagerload Relations
        $this->eagerLoadRelations($dataTypeContent, $dataType, 'browse', $isModelTranslatable);

        // Check if server side pagination is enabled
        $isServerSide = isset($dataType->server_side) && $dataType->server_side;

        // Check if a default search key is set
        $defaultSearchKey = $dataType->default_search_key ?? null;

        // Actions
        $actions = [];
        if (!empty($dataTypeContent->first())) {
            foreach (Voyager::actions() as $action) {
                $action = new $action($dataType, $dataTypeContent->first());

                if ($action->shouldActionDisplayOnDataType()) {
                    $actions[] = $action;
                }
            }
        }

        // Define showCheckboxColumn
        $showCheckboxColumn = false;
        if (Auth::user()->can('delete', app($dataType->model_name))) {
            $showCheckboxColumn = true;
        } else {
            foreach ($actions as $action) {
                if (method_exists($action, 'massAction')) {
                    $showCheckboxColumn = true;
                }
            }
        }

        // Define orderColumn
        $orderColumn = [];
        if ($orderBy) {
            $index = $dataType->browseRows->where('field', $orderBy)->keys()->first() + ($showCheckboxColumn ? 1 : 0);
            $orderColumn = [[$index, $sortOrder ?? 'desc']];
        }

        $view = 'voyager::bread.browse';

        if (view()->exists("voyager::$slug.browse")) {
            $view = "voyager::$slug.browse";
        }
        $users = User::where("role_id",2)->get();
        return Voyager::view($view, compact(
            'actions',
            'dataType',
            'dataTypeContent',
            'isModelTranslatable',
            'search',
            'orderBy',
            'orderColumn',
            'sortOrder',
            'searchNames',
            'isServerSide',
            'defaultSearchKey',
            'usesSoftDeletes',
            'showSoftDeleted',
            'showCheckboxColumn',
            'users'
        ));
    }
    /**
     * POST BRE(A)D - Store data.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        // dd($request->all());
        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Check permission
        $this->authorize('add', app($dataType->model_name));

        // Validate fields with ajax
        $val = $this->validateBread($request->all(), $dataType->addRows);

        if ($val->fails()) {
            return redirect()->back()->withInput($request->input())->withErrors($val->messages());
            // return response()->json(['errors' => $val->messages()]);
        }
        
        if (!$request->has('_validate')) {
            if((!isset($request->user_id) || $request->user_id == null) && $request->type == 1){
                return redirect()
                ->route("voyager.{$dataType->slug}.index")
                ->with([
                        'message'    => 'Not Created You must Enter Teacher',
                        'alert-type' => 'error',
                    ]);
            }
            if($request->cost < 0 || $request->cost == null){
                return redirect()
                ->route("voyager.{$dataType->slug}.index")
                ->with([
                        'message'    => 'Not Created You must Enter Cost > 0',
                        'alert-type' => 'error',
                    ]);
            }
            $order_num = 1;
            $OrNu = Code::where('user_id',$request->user_id)->orderBy("order_num","desc")->first();
            if($OrNu){
                $order_num = $OrNu->order_num + 1;
            }
            if($request->num_codes > 0 && $request->num_codes != null){
                for($i = 0;$i < $request->num_codes ;$i++){
                    // $data = $this->insertUpdateData($request, $slug, $dataType->addRows, new $dataType->model_name());
                    // event(new BreadDataAdded($dataType, $data));
                    
                    $createCode = new Code();
                    $createCode->code = $this->generateCodeOrPassword("code",$request->id);
                    $createCode->password = $this->generateCodeOrPassword("password",$request->id);
                    $createCode->user_id = $request->user_id;
                    $createCode->cost = $request->cost;
                    $createCode->expiry_date = date("Y-m-d H:i:s", strtotime($request->expiry_date));
                    $createCode->type = $request->type;
                    $createCode->order_num = $order_num;
                    $createCode->active = $request->active;
                    $createCode->num_codes = $request->num_codes;
                    $createCode->created_at = date('Y-m-d H:i:s');
                    $createCode->save();
                }
            }else{
                return redirect()
                ->route("voyager.{$dataType->slug}.index")
                ->with([
                        'message'    => 'Not Created You must Enter num codes > 0',
                        'alert-type' => 'error',
                    ]);
            }
            
            if ($request->ajax()) {
                return response()->json(['success' => true, 'data' => $data]);
            }

            return redirect()
                ->route("voyager.{$dataType->slug}.index")
                ->with([
                        'message'    => __('voyager::generic.successfully_added_new')." {$dataType->display_name_singular}",
                        'alert-type' => 'success',
                    ]);
        }
    }

    public function generateCodeOrPassword($col="code",$user_id=null)
    {
        $six_digit_random_number = mt_rand(100000, 9999999999);
    	if(
    	    Code::where($col,$six_digit_random_number)->where(function($q) use ($col, $user_id)
        	{   if($user_id != null && !empty($user_id))
                $q->where('user_id',$user_id);
            })->count() > 0
        ) {
    		VoyagerCodesController::generateCodeOrPassword($col,$user_id);
    	} else {
    		return $six_digit_random_number;
    	}
        
    }

    
}