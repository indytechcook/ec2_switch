<?php

// Check for the aws libary first
if (file_exists(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'aws-sdk' . DIRECTORY_SEPARATOR . 'sdk.class.php'))
{
  include_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'aws-sdk' . DIRECTORY_SEPARATOR . 'sdk.class.php';
}
else {
  exit;
}

/**
 * The Switch Class
 */
class EBSwitch {
  protected $group_name;
  protected $group_resources;
  protected $active_resources;
  protected $cluster_resources;
  protected $elb_name;
  protected $active_cluster;

  const ACTIVE = 'TRUE';
  const INACTIVE = 'FALSE';

  public function __construct($elb_name, $group_name) {
    $this->group_name = $group_name;
    $this->elb_name = $elb_name;
    $$this->cluster_resources = new CFArray();
  }

  public function switch_to($cluster) {
    if (empty($this->cluster_resources[$cluster])) {
      $this->get_cluster_instances($cluster);
    }

    if (empty($this->active_cluster)) {
      $this->set_active_instances();
    }

    $this->register($cluster);
    $this->deregister();
    $this->set_active_cluster($cluster);
  }

  public function register($cluster) {
    $eb = new AmazonEC2();

    if (empty($this->cluster_resources[$cluster])) {
      $this->get_cluster_instances($cluster);
    }

    // Register
    $response = $eb->register_instances_with_load_balancer(
      $this->elb_name,
      $this->cluster_resources[$cluster]
    );

    if (!$response->isOK()) {
      throw new EBSwitchException('ECs not registered');
    }

    return $this;
  }

  public function deregister() {
    $eb = new AmazonEC2();

    if (empty($this->active_cluster)) {
      $this->set_active_instances();
    }

    // Register
    $response = $eb->describe_reserved_instances(
      $this->elb_name,
      $this->cluster_resources[$this->get_active_cluster()]
    );

    if (!$response->isOK()) {
      throw new EBSwitchException('ECs not registered');
    }

    return $this;
  }

  public function get_cluster_instances($cluster) {
    $this->cluster_resources[$cluster] =  $this->get_instances_by_tags(
      array(
        'cluster' => $cluster,
      )
    );

    return $this;
  }

  /**
   * Set the active resources for the group
   *
   * @return EBSwitch
   */
  public function set_active_instances() {
    $this->active_resources = $this->get_instances_by_tags(
      array(
        'group' => $this->group_name,
        'active' => self::ACTIVE,
      ),
      array($this, 'active_callback')
    );

    return $this;
  }

  /**
   * Internal callback to set teh active cluster
   *
   * @param CFResponse $response
   * @return array|CFArray
   */
  public function active_callback(CFResponse $response) {
    // Get the cluster of the first item
    if ($nodes = $response->body->query('/tagSet/item[key="cluster"]')) {
      $node = reset($nodes);
    }

    $this->set_active_cluster($node['value']);

    return $this->get_resource_ids($response);
  }

  public function set_active_cluster($cluster) {
    $this->active_cluster = $cluster;
    return $this;
  }

  public function get_active_cluster() {
    return $this->active_cluster;
  }

  /**
   * Set the group_resources for the group
   *
   * @return EBSwitch
   */
  public function set_group_instances() {
    $this->group_resources = $this->get_instances_by_tags(
      array(
        'group' => $this->group_name,
      )
    );

    return $this;
  }

  /**
   * Get the ec instances for a tag filter
   *
   * @throws EBSwitchException
   * @param array $filters array('key' => 'value')
   * @return array|CFArray
   */
  protected function get_instances_by_tags(array $filters, $callback = NULL) {
    // Build filter array
    $ecs_filters = array();
    foreach ($filters as $key => $value) {
      $ecs_filters[] = array(
        'Name' => $key,
        'Value' => $value,
      );
    }

    $ec2 = new AmazonEC2();
    $response = $ec2->describe_tags(array(
      'Filter' => $ecs_filters,
    ));

    if (!$response->isOK()) {
      throw new EBSwitchException('EC Not Loaded');
    }

    if (isset($callback) && is_callable($callback)) {
      return call_user_func_array($callback, array($response));
    }
    else {
      return $this->get_resource_ids($response);
    }
  }

  /**
   * Get the resources IDS from a response from describe_tags
   *
   * @throws EBSwitchException
   * @param CFResponse $response
   * @return array|CFArray
   */
  protected function get_resource_ids(CFResponse $response) {
    if (!$response->isOK()) {
      throw new EBSwitchException('Response Not valid');
    }

    $resource_ids = new CFArray(array());
    foreach ($response->body->to_array() as $item) {
      $resource_ids[] = $item['resourceId'];
    }

    return $resource_ids;
  }
}

class EBSwitchException extends Exception {}
