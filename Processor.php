<?php

/**
 * Extroller_Processor
 *
 * @category   Addon
 * @package    addon.extroller
 * @author     Ebine Yutaka <ebine.yutaka@sabel.jp>
 * @copyright  2004-2008 Mori Reo <mori.reo@sabel.jp>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 */
class Extroller_Processor extends Sabel_Bus_Processor
{
  public function execute(Sabel_Bus $bus)
  {
    $response = $bus->get("response");
    $status   = $response->getStatus();
    if ($status->isFailure()) return;
    
    $controller = $bus->get("controller");
    $controller->mixin("Extroller_Mixin");
    
    $request = $bus->get("request");
    $gets    = $request->fetchGetValues();
    $posts   = $request->fetchPostValues();
    $params  = $request->fetchParameterValues();
    $files   = $request->getFiles();
    $values  = array_merge($gets, $posts, $params, $files);
    
    if (count($values) !== (count($gets) + count($posts) + count($params) + count($files))) {
      l("Extroller: request key overlaps.", SBL_LOG_DEBUG);
      return $status->setCode(Sabel_Response::BAD_REQUEST);
    }
    
    if ($files) {
      foreach ($files as $name => $file) {
        $posts[$name] = $file;
      }
    }
    
    $session = $bus->get("session");
    
    if ($session && $flash_message = $session->read(Extroller_Mixin::FLASH_SESSION_KEY)) {
      $controller->flash_message = $flash_message;
      $session->delete(Extroller_Mixin::FLASH_SESSION_KEY);
    }
    
    $action = $bus->get("destination")->getAction();
    
    if ($controller->hasMethod($action)) {
      $reader = Sabel_Annotation_Reader::create();
      $annots = $reader->readMethodAnnotation($controller, $action);
      
      if (isset($annots["httpMethod"])) {
        $allows = $annots["httpMethod"][0];
        if (!$this->isMethodAllowed($request, $allows)) {
          $response->setHeader("Allow", implode(",", array_map("strtoupper", $allows)));
          return $status->setCode(Sabel_Response::METHOD_NOT_ALLOWED);
        }
      }
      
      if (isset($annots["trim"]) && !realempty($annots["trim"][0])) {
        $trimfunc = (extension_loaded("mbstring")) ? "mb_trim" : "trim";
        foreach ($annots["trim"][0] as $rkey) {
          if (isset($values[$rkey]) && is_string($values[$rkey])) {
            $_value = $trimfunc($values[$rkey]);
            $_value = (realempty($_value)) ? null : $_value;
            $values[$rkey] = $_value;
            
            if (isset($gets[$rkey])) {
              $gets[$rkey] = $_value;
            } elseif (isset($posts[$rkey])) {
              $posts[$rkey] = $_value;
            }
          }
        }
      }
      
      if (isset($annots["check"]) && ($request->isGet() || $request->isPost())) {
        if (!$result = $this->validate($controller, $values, $request, $annots["check"])) {
          return $status->setCode(Sabel_Response::BAD_REQUEST);
        }
      }
    }
    
    foreach ($values as $name => $value) {
      $controller->setAttribute($name, $value);
    }
    
    $controller->setAttribute("REQUEST_VARS", $values);
    $controller->setAttribute("GET_VARS",     $gets);
    $controller->setAttribute("POST_VARS",    $posts);
  }
  
  protected function isMethodAllowed($request, $allows)
  {
    $result = true;
    foreach ($allows as $method) {
      if (!($result = $request->{"is" . $method}())) break;
    }
    
    return $result;
  }
  
  protected function validate($controller, $values, $request, $checks)
  {
    $validator = new Validator();
    
    foreach ($checks as $check) {
      $name = array_shift($check);
      foreach ($check as $method) {
        $validator->add($name, $method);
      }
    }
    
    $controller->setAttribute("validator", $validator);
    
    $result = true;
    if (!$validator->validate($values)) {
      if ($request->isPost()) {
        $controller->setAttribute("errors", $validator->getErrors());
      } else {
        $result = false;
      }
    }
    
    return $result;
  }
}
