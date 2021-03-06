<?php
App::uses('GeocoderBehavior', 'Tools.Model/Behavior');
App::uses('Set', 'Utility');
App::uses('AppModel', 'Model');
App::uses('AppController', 'Controller');

class GeocoderBehaviorTest extends CakeTestCase {

	public $fixtures = array(
		'core.comment', 'plugin.tools.address'
	);

	public function startTest() {
		$this->Comment = ClassRegistry::init('Comment');

		$this->Comment->Behaviors->attach('Tools.Geocoder', array('real'=>false));
	}

	public function testDistance() {
		$res = $this->Comment->distance(12, 14);
		$expected = '6371.04 * ACOS( COS( PI()/2 - RADIANS(90 - Comment.lat)) * COS( PI()/2 - RADIANS(90 - 12)) * COS( RADIANS(Comment.lat) - RADIANS(14)) + SIN( PI()/2 - RADIANS(90 - Comment.lng)) * SIN( PI()/2 - RADIANS(90 - 12)))';
		$this->assertEquals($expected, $res);

		$this->Comment->Behaviors->detach('Geocoder');
		$this->Comment->Behaviors->attach('Tools.Geocoder', array('lat'=>'x', 'lng'=>'y'));
		$res = $this->Comment->distance(12, 14);
		$expected = '6371.04 * ACOS( COS( PI()/2 - RADIANS(90 - Comment.x)) * COS( PI()/2 - RADIANS(90 - 12)) * COS( RADIANS(Comment.x) - RADIANS(14)) + SIN( PI()/2 - RADIANS(90 - Comment.y)) * SIN( PI()/2 - RADIANS(90 - 12)))';
		$this->assertEquals($expected, $res);
	}

	public function testDistanceField() {
		$res = $this->Comment->distanceField(12, 14);
		$expected = '6371.04 * ACOS( COS( PI()/2 - RADIANS(90 - Comment.lat)) * COS( PI()/2 - RADIANS(90 - 12)) * COS( RADIANS(Comment.lat) - RADIANS(14)) + SIN( PI()/2 - RADIANS(90 - Comment.lng)) * SIN( PI()/2 - RADIANS(90 - 12))) AS Comment.distance';
		$this->assertEquals($expected, $res);
	}

	public function testSetDistanceAsVirtualField() {
		$this->Address = ClassRegistry::init('Address');
		$this->Address->Behaviors->attach('Tools.Geocoder');
		$this->Address->setDistanceAsVirtualField(13.3, 19.2);
		$options = array('order' => array('Address.distance' => 'ASC'));
		$res = $this->Address->find('all', $options);
		$this->assertTrue($res[0]['Address']['distance'] < $res[1]['Address']['distance']);
		$this->assertTrue($res[1]['Address']['distance'] < $res[2]['Address']['distance']);
		$this->assertTrue($res[0]['Address']['distance'] > 460 && $res[0]['Address']['distance'] < 490);
	}

	public function testSetDistanceAsVirtualFieldInMiles() {
		$this->Address = ClassRegistry::init('Address');
		$this->Address->Behaviors->attach('Tools.Geocoder', array('unit' => GeocodeLib::UNIT_MILES));
		$this->Address->setDistanceAsVirtualField(13.3, 19.2);
		$options = array('order' => array('Address.distance' => 'ASC'));
		$res = $this->Address->find('all', $options);
		$this->assertTrue($res[0]['Address']['distance'] < $res[1]['Address']['distance']);
		$this->assertTrue($res[1]['Address']['distance'] < $res[2]['Address']['distance']);
		$this->assertTrue($res[0]['Address']['distance'] > 270 && $res[0]['Address']['distance'] < 310);
	}

	public function testPagination() {
		$this->Controller = new TestController(new CakeRequest(null, false), null);
		$this->Controller->constructClasses();
		$this->Controller->Address->Behaviors->attach('Tools.Geocoder');
		$this->Controller->Address->setDistanceAsVirtualField(13.3, 19.2);
		$this->Controller->paginate = array(
			'conditions'=>array('distance <' => 3000),
			'order' => array('distance' => 'ASC')
		);
		$res = $this->Controller->paginate();
		$this->assertEquals(2, count($res));
		$this->assertTrue($res[0]['Address']['distance'] < $res[1]['Address']['distance']);
	}

	public function testValidate() {
		$is = $this->Comment->validateLatitude(44);
		$this->assertTrue($is);

		$is = $this->Comment->validateLatitude(110);
		$this->assertFalse($is);

		$is = $this->Comment->validateLongitude(150);
		$this->assertTrue($is);

		$is = $this->Comment->validateLongitude(-190);
		$this->assertFalse($is);

		$this->Comment->validator()->add('lat', 'validateLatitude', array('rule'=>'validateLatitude', 'message'=>'validateLatitudeError'));
		$this->Comment->validator()->add('lng', 'validateLongitude', array('rule'=>'validateLongitude', 'message'=>'validateLongitudeError'));
		$data = array(
			'lat' => 44,
			'lng' => 190,
		);
		$this->Comment->set($data);
		$res = $this->Comment->validates();
		$this->assertFalse($res);
		$expectedErrors = array(
			'lng' => array(__('validateLongitudeError'))
		);
		$this->assertEquals($expectedErrors, $this->Comment->validationErrors);
	}

	/**
	 * geocoding tests using the google webservice
	 */
	public function testBasic() {
		echo '<h3>'.__FUNCTION__.'</h3>';

		$data = array(
			'street' => 'Krebenweg 22',
			'zip' => '74523',
			'city' => 'Bibersfeld'
		);
		$res = $this->Comment->save($data);
		debug($res);
		$this->assertTrue(!empty($res['Comment']['lat']) && !empty($res['Comment']['lng']) && round($res['Comment']['lat']) === 49.0 && round($res['Comment']['lng']) === 10.0);
		// accuracy = 4


		# inconclusive
		$data = array(
	 		//'street' => 'Leopoldstraße',
			'city' => 'München'
		);
		$res = $this->Comment->save($data);
		$this->assertEquals('', $this->Comment->Behaviors->Geocoder->Geocode->error());

		debug($res);
		$this->assertTrue(!empty($res['Comment']['lat']) && !empty($res['Comment']['lng']));
		$this->assertEquals('München, Deutschland', $res['Comment']['geocoder_result']['formatted_address']);

		$data = array(
			'city' => 'Bibersfeld'
		);
		$res = $this->Comment->save($data);
		debug($res);
		$this->assertTrue(!empty($res));
		$this->assertEquals('', $this->Comment->Behaviors->Geocoder->Geocode->error());
	}

	public function testMinAccLow() {
		echo '<h3>'.__FUNCTION__.'</h3>';

		$this->Comment->Behaviors->detach('Geocoder');
		$this->Comment->Behaviors->attach('Tools.Geocoder', array('real'=>false, 'min_accuracy'=>0));
		// accuracy = 1
		$data = array(
	 		//'street' => 'Leopoldstraße',
			'city' => 'Deutschland'
		);
		$res = $this->Comment->save($data);
		debug($this->Comment->Behaviors->Geocoder->Geocode->error()).BR;

		debug($res);

		//debug($this->Comment->Behaviors->Geocoder->Geocode->debug());
		$this->assertTrue(!empty($res['Comment']['lat']) && !empty($res['Comment']['lng']));

	}

	public function testMinAccHigh() {
		echo '<h3>'.__FUNCTION__.'</h3>';

		$this->Comment->Behaviors->detach('Geocoder');
		$this->Comment->Behaviors->attach('Tools.Geocoder', array('real'=>false, 'min_accuracy'=>4));
		// accuracy = 1
		$data = array(
	 		//'street' => 'Leopoldstraße',
			'city' => 'Deutschland'
		);
		$res = $this->Comment->save($data);
		debug($this->Comment->Behaviors->Geocoder->Geocode->error()).BR;

		debug($res);

		//debug($this->Comment->Behaviors->Geocoder->Geocode->debug());
		$this->assertTrue(!isset($res['Comment']['lat']) && !isset($res['Comment']['lng']));

	}


	public function testMinInc() {
		echo '<h3>'.__FUNCTION__.'</h3>';

		$this->Comment->Behaviors->detach('Geocoder');
		$this->Comment->Behaviors->attach('Tools.Geocoder', array('real'=>false, 'min_accuracy'=>GeocodeLib::ACC_SUBLOC));

		$this->assertEquals(GeocodeLib::ACC_SUBLOC, $this->Comment->Behaviors->Geocoder->settings['Comment']['min_accuracy']);

		// accuracy = 1
		$data = array(
	 		//'street' => 'Leopoldstraße',
			'city' => 'Neustadt'
		);
		$res = $this->Comment->save($data);
		debug($this->Comment->Behaviors->Geocoder->Geocode->error()).BR;

		debug($this->Comment->Behaviors->Geocoder->Geocode->getResult()).BR;

		debug($res);

		//debug($this->Comment->Behaviors->Geocoder->Geocode->debug());
		$this->assertTrue(!isset($res['Comment']['lat']) && !isset($res['Comment']['lng']));

	}

	public function testMinIncAllowed() {
		echo '<h3>'.__FUNCTION__.'</h3>';

		$this->Comment->Behaviors->detach('Geocoder');
		$this->Comment->Behaviors->attach('Tools.Geocoder', array('real'=>false, 'allow_inconclusive'=>true));
		// accuracy = 1
		$data = array(
	 		//'street' => 'Leopoldstraße',
			'city' => 'Neustadt'
		);
		$res = $this->Comment->save($data);
		debug($this->Comment->Behaviors->Geocoder->Geocode->error()).BR;

		debug($this->Comment->Behaviors->Geocoder->Geocode->url()).BR;

		debug($this->Comment->Behaviors->Geocoder->Geocode->getResult()).BR;

		debug($res);

		//debug($this->Comment->Behaviors->Geocoder->Geocode->debug());
		$this->assertTrue(!empty($res['Comment']['lat']) && !empty($res['Comment']['lng']));

	}

	public function testExpect() {
		$this->Comment->Behaviors->detach('Geocoder');
		$this->Comment->Behaviors->attach('Tools.Geocoder', array('real'=>false, 'expect'=>array('postal_code')));
		// accuracy = 1
		$data = array(
	 		//'street' => 'Leopoldstraße',
			'city' => 'Bibersfeld'
		);
		$res = $this->Comment->save($data);
		debug($this->Comment->Behaviors->Geocoder->Geocode->error()).BR;

		debug($res);

		debug($this->Comment->Behaviors->Geocoder->Geocode->debug());
		$this->assertTrue(empty($res['Comment']['lat']) && empty($res['Comment']['lng']));

		$data = array(
	 		//'street' => 'Leopoldstraße',
			'city' => '74523'
		);
		$res = $this->Comment->save($data);
		debug($this->Comment->Behaviors->Geocoder->Geocode->error()).BR;

		debug($res);

		//debug($this->Comment->Behaviors->Geocoder->Geocode->debug());
		$this->assertTrue(!empty($res['Comment']['lat']) && !empty($res['Comment']['lng']));
	}

}


class TestController extends AppController {

	public $uses = array('Address');

}


