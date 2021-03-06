<?php
/*
 * This file is part of facturacion_base
 * Copyright (C) 2013-2017  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_model('almacen.php');
require_model('articulo.php');
require_model('articulo_combinacion.php');
require_model('articulo_proveedor.php');
require_model('atributo.php');
require_model('atributo_valor.php');
require_model('familia.php');
require_model('fabricante.php');
require_model('impuesto.php');
require_model('regularizacion_stock.php');
require_model('stock.php');
require_model('tarifa.php');

class ventas_articulo extends fs_controller
{
   public $allow_delete;
   public $almacen;
   public $articulo;
   public $fabricante;
   public $familia;
   public $hay_atributos;
   public $impuesto;
   public $mostrar_boton_publicar;
   public $mostrar_tab_atributos;
   public $mostrar_tab_precios;
   public $mostrar_tab_stock;
   public $nuevos_almacenes;
   public $stock;
   public $stocks;
   public $equivalentes;
   public $regularizaciones;
   public $agrupar;
   public $mgrupo;
   public function __construct()
   {
      parent::__construct(__CLASS__, 'Articulo', 'ventas', FALSE, FALSE);
   }
   
   protected function private_core()
   {
      /// ¿El usuario tiene permiso para eliminar en esta página?
      $this->allow_delete = $this->user->allow_delete_on(__CLASS__);
      
      $articulo = new articulo();
      $this->almacen = new almacen();
      $this->articulo = FALSE;
      $this->fabricante = new fabricante();
      $this->impuesto = new impuesto();
      $this->stock = new stock();
      //Inicializamos la variable agrupar vacia
      $this->agrupar = '';
      /**
       * Si hay alguna extensión de tipo config y texto no_button_publicar,
       * desactivamos el botón publicar.
       */
      $this->mostrar_boton_publicar = TRUE;
      foreach($this->extensions as $ext)
      {
         if($ext->type == 'config' AND $ext->text == 'no_button_publicar')
         {
            $this->mostrar_boton_publicar = FALSE;
            break;
         }
      }
      
      //Si nos llega la variable agrupar de un GET lo asignamos
      $agrupar = \filter_input(INPUT_GET, 'agrupar');
      $this->agrupar = ($agrupar)?$agrupar:$this->agrupar;
      
      /**
       * Si hay atributos, mostramos el tab atributos.
       */
      $this->hay_atributos = FALSE;
      $this->mostrar_tab_atributos = FALSE;
      $atri0 = new atributo();
      foreach($atri0->all() as $atributo)
      {
         $this->mostrar_tab_atributos = TRUE;
         $this->hay_atributos = TRUE;
         break;
      }
      
      /**
       * Si hay alguna extensión de tipo config y texto no_tab_recios,
       * desactivamos la pestaña precios.
       */
      $this->mostrar_tab_precios = TRUE;
      foreach($this->extensions as $ext)
      {
         if($ext->type == 'config' AND $ext->text == 'no_tab_precios')
         {
            $this->mostrar_tab_precios = FALSE;
            break;
         }
      }
      
      /**
       * Si hay alguna extensión de tipo config y texto no_tab_stock,
       * desactivamos la pestaña stock.
       */
      $this->mostrar_tab_stock = TRUE;
      foreach($this->extensions as $ext)
      {
         if($ext->type == 'config' AND $ext->text == 'no_tab_stock')
         {
            $this->mostrar_tab_stock = FALSE;
            break;
         }
      }
      
      if( isset($_POST['referencia']) )
      {
         $this->articulo = $articulo->get($_POST['referencia']);
      }
      else if( isset($_GET['ref']) )
      {
         $this->articulo = $articulo->get($_GET['ref']);
      }
      
      if($this->articulo)
      {
         $this->modificar(); /// todas las modificaciones van aquí
         $this->page->title = $this->articulo->referencia;
         
         if($this->articulo->bloqueado)
         {
            $this->new_advice("Este artículo está bloqueado / obsoleto.");
         }
         
         /**
          * Si no es un artículo con atributos, ocultamos la pestaña
          */
         if($this->articulo->tipo != 'atributos')
         {
            $this->mostrar_tab_atributos = FALSE;
         }
         
         /**
          * Si está desactivado el control de stok en el artículo, ocultamos la pestaña
          */
         if($this->articulo->nostock)
         {
            $this->mostrar_tab_stock = FALSE;
         }
         
         $this->familia = $this->articulo->get_familia();
         if(!$this->familia)
         {
            $this->familia = new familia();
         }
         
         $this->fabricante = $this->articulo->get_fabricante();
         if(!$this->fabricante)
         {
            $this->fabricante = new fabricante();
         }
         
         $this->stocks = $this->articulo->get_stock();
         /// metemos en un array los almacenes que no tengan stock de este producto
         $this->nuevos_almacenes = array();
         foreach($this->almacen->all() as $a)
         {
            $encontrado = FALSE;
            foreach($this->stocks as $s)
            {
               if( $a->codalmacen == $s->codalmacen )
               {
                  $encontrado = TRUE;
               }
            }
            if( !$encontrado )
            {
               $this->nuevos_almacenes[] = $a;
            }
         }
         
         $reg = new regularizacion_stock();
         $this->regularizaciones = $reg->all_from_articulo($this->articulo->referencia);
         
         $this->equivalentes = $this->articulo->get_equivalentes();
      }
      else
      {
         $this->new_error_msg("Artículo no encontrado.", 'error', FALSE, FALSE);
      }
   }
   
   public function url()
   {
      if($this->articulo)
      {
         return $this->articulo->url();
      }
      else
         return $this->page->url();
   }
   
   /**
    * Decide qué modificación hacer en función de los parametros del formulario.
    */
   private function modificar()
   {
      if( isset($_POST['pvpiva']) )
      {
         $this->edit_precio();
      }
      else if( isset($_POST['almacen']) )
      {
         $this->edit_stock();
      }
      else if( isset($_GET['deletereg']) )
      {
         $this->eliminar_regulacion();
      }
      else if( isset($_POST['imagen']) )
      {
         $this->edit_imagen();
      }
      else if( isset($_GET['delete_img']) )
      {
         $this->eliminar_imagen();
      }
      else if( isset($_POST['referencia']) )
      {
         $this->modificar_articulo();
      }
      else if( isset($_GET['recalcular_stock']) )
      {
         $this->calcular_stock_real();
      }
      else if( isset($_POST['nueva_combi']) )
      {
         $this->nueva_combinacion();
      }
      else if( isset($_POST['editar_combi']) )
      {
         $this->edit_combinacion();
      }
      else if( isset($_GET['delete_combi']) )
      {
         $this->eliminar_combinacion();
      }
   }
   
   private function edit_precio()
   {
      $this->articulo->set_impuesto( $_POST['codimpuesto'] );
      $this->articulo->set_pvp_iva( floatval($_POST['pvpiva']) );
      
      if( isset($_POST['preciocoste']) )
      {
         $this->articulo->preciocoste = floatval($_POST['preciocoste']);
      }
      
      if( $this->articulo->save() )
      {
         $this->new_message("Precio modificado correctamente.");
      }
      else
      {
         $this->new_error_msg("Error al modificar el precio.");
      }
   }
   
   private function edit_stock()
   {
      if($_POST['cantidadini'] == $_POST['cantidad'])
      {
         /// sin cambios de stock, pero aún así guardamos la ubicación
         foreach($this->articulo->get_stock() as $stock)
         {
            if($stock->codalmacen == $_POST['almacen'])
            {
               /// forzamos que se asigne el nombre del almacén
               $stock->nombre();
               
               $stock->ubicacion = $_POST['ubicacion'];
               if( $stock->save() )
               {
                  $this->new_message('Cambios guardados correctamente.');
               }
            }
         }
      }
      else if( $this->articulo->set_stock($_POST['almacen'], $_POST['cantidad']) )
      {
         $this->new_message("Stock guardado correctamente.");
         
         /// añadimos la regularización
         foreach($this->articulo->get_stock() as $stock)
         {
            if($stock->codalmacen == $_POST['almacen'])
            {
               /// forzamos que se asigne el nombre del almacén
               $stock->nombre();
               
               $stock->ubicacion = $_POST['ubicacion'];
               $stock->save();
               
               $regularizacion = new regularizacion_stock();
               $regularizacion->idstock = $stock->idstock;
               $regularizacion->cantidadini = floatval($_POST['cantidadini']);
               $regularizacion->cantidadfin = floatval($_POST['cantidad']);
               $regularizacion->codalmacendest = $_POST['almacen'];
               $regularizacion->motivo = $_POST['motivo'];
               $regularizacion->nick = $this->user->nick;
               if( $regularizacion->save() )
               {
                  $this->new_message('Cambios guardados correctamente.');
               }
               break;
            }
         }
      }
      else
      {
         $this->new_error_msg("Error al guardar el stock.");
      }
   }
   
   private function eliminar_regulacion()
   {
      $reg = new regularizacion_stock();
      $regularizacion = $reg->get($_GET['deletereg']);
      if($regularizacion)
      {
         if( $regularizacion->delete() )
         {
            $this->new_message('Regularización eliminada correctamente.');
         }
         else
         {
            $this->new_error_msg('Error al eliminar la regularización.');
         }
      }
      else
      {
         $this->new_error_msg('Regularización no encontrada.');
      }
   }
   
   private function edit_imagen()
   {
      if( is_uploaded_file($_FILES['fimagen']['tmp_name']) )
      {
         $png = ( substr( strtolower($_FILES['fimagen']['name']), -3) == 'png' );
         $this->articulo->set_imagen( file_get_contents($_FILES['fimagen']['tmp_name']), $png );
         if( $this->articulo->save() )
         {
            $this->new_message("Imagen del articulo modificada correctamente");
         }
         else
            $this->new_error_msg("¡Error al guardar la imagen del articulo!");
      }
   }
   
   private function eliminar_imagen()
   {
      $this->articulo->set_imagen(NULL);
      if( $this->articulo->save() )
      {
         $this->new_message("Imagen del articulo eliminada correctamente");
      }
      else
         $this->new_error_msg("¡Error al eliminar la imagen del articulo!");
   }
   
   private function modificar_articulo()
   {
      $this->articulo->descripcion = $_POST['descripcion'];
      
      $this->articulo->tipo = NULL;
      if($_POST['tipo'] != '')
      {
         $this->articulo->tipo = $_POST['tipo'];
      }
      
      $this->articulo->codfamilia = NULL;
      if($_POST['codfamilia'] != '')
      {
         $this->articulo->codfamilia = $_POST['codfamilia'];
      }
      
      $this->articulo->codfabricante = NULL;
      if($_POST['codfabricante'] != '')
      {
         $this->articulo->codfabricante = $_POST['codfabricante'];
      }
      
      /// ¿Existe ya ese código de barras?
      if($_POST['codbarras'] != '')
      {
         $arts = $this->articulo->search_by_codbar($_POST['codbarras']);
         if($arts)
         {
            foreach($arts as $art2)
            {
               if($art2->referencia != $this->articulo->referencia)
               {
                  $this->new_advice('Ya hay un artículo con este mismo código de barras. '
                          . 'En concreto, el artículo <a href="'.$art2->url().'">'.$art2->referencia.'</a>.');
                  break;
               }
            }
         }
      }
      
      $this->articulo->codbarras = $_POST['codbarras'];
      $this->articulo->partnumber = $_POST['partnumber'];
      $this->articulo->equivalencia = $_POST['equivalencia'];
      $this->articulo->bloqueado = isset($_POST['bloqueado']);
      $this->articulo->controlstock = isset($_POST['controlstock']);
      $this->articulo->nostock = isset($_POST['nostock']);
      $this->articulo->secompra = isset($_POST['secompra']);
      $this->articulo->sevende = isset($_POST['sevende']);
      $this->articulo->publico = isset($_POST['publico']);
      $this->articulo->observaciones = $_POST['observaciones'];
      $this->articulo->stockmin = floatval($_POST['stockmin']);
      $this->articulo->stockmax = floatval($_POST['stockmax']);
      $this->articulo->trazabilidad = isset($_POST['trazabilidad']);
      
      if( $this->articulo->save() )
      {
         $this->new_message("Datos del articulo modificados correctamente");
         
         $img = $this->articulo->imagen_url();
         $this->articulo->set_referencia($_POST['nreferencia']);
         
         /// ¿Renombramos la imagen?
         if($img)
         {
            @rename($img, $this->articulo->imagen_url());
         }
         
         /**
          * Renombramos la referencia en el resto de tablas: lineasalbaranes, lineasfacturas...
          */
         if( $this->db->table_exists('lineasalbaranescli') )
         {
            $this->db->exec("UPDATE lineasalbaranescli SET referencia = ".$this->empresa->var2str($_POST['nreferencia'])
                    ." WHERE referencia = ".$this->empresa->var2str($_POST['referencia']).";");
         }
         
         if( $this->db->table_exists('lineasalbaranesprov') )
         {
            $this->db->exec("UPDATE lineasalbaranesprov SET referencia = ".$this->empresa->var2str($_POST['nreferencia'])
                    ." WHERE referencia = ".$this->empresa->var2str($_POST['referencia']).";");
         }
         
         if( $this->db->table_exists('lineasfacturascli') )
         {
            $this->db->exec("UPDATE lineasfacturascli SET referencia = ".$this->empresa->var2str($_POST['nreferencia'])
                    ." WHERE referencia = ".$this->empresa->var2str($_POST['referencia']).";");
         }
         
         if( $this->db->table_exists('lineasfacturasprov') )
         {
            $this->db->exec("UPDATE lineasfacturasprov SET referencia = ".$this->empresa->var2str($_POST['nreferencia'])
                    ." WHERE referencia = ".$this->empresa->var2str($_POST['referencia']).";");
         }
         
         /// esto es una personalización del plugin producción, será eliminado este código en futuras versiones.
         if( $this->db->table_exists('lineasfabricados') )
         {
            $this->db->exec("UPDATE lineasfabricados SET referencia = ".$this->empresa->var2str($_POST['nreferencia'])
                    ." WHERE referencia = ".$this->empresa->var2str($_POST['referencia']).";");
         }
      }
      else
         $this->new_error_msg("¡Error al guardar el articulo!");
   }
   
   private function nueva_combinacion()
   {
      $comb1 = new articulo_combinacion();
      $comb1->referencia = $this->articulo->referencia;
      $comb1->impactoprecio = floatval($_POST['impactoprecio']);
      
      if($_POST['refcombinacion'])
      {
         $comb1->refcombinacion = $_POST['refcombinacion'];
      }
      
      if($_POST['codbarras'])
      {
         $comb1->codbarras = $_POST['codbarras'];
      }
      
      $error = TRUE;
      $valor0 = new atributo_valor();
      for($i = 0; $i < 10; $i++)
      {
         if( isset($_POST['idvalor_'.$i]) )
         {
            if($_POST['idvalor_'.$i])
            {
               $valor = $valor0->get($_POST['idvalor_'.$i]);
               if($valor)
               {
                  $comb1->id = NULL;
                  $comb1->idvalor = $valor->id;
                  $comb1->nombreatributo = $valor->nombre();
                  $comb1->valor = $valor->valor;
                  $error = !$comb1->save();
               }
            }
         }
         else
         {
            break;
         }
      }
      
      if($error)
      {
         $this->new_error_msg('Error al guardar la combinación.');
      }
      else
      {
         $this->new_message('Combinación guardada correctamente.');
      }
   }
   
   private function edit_combinacion()
   {
      $comb1 = new articulo_combinacion();
      foreach($comb1->all_from_codigo($_POST['editar_combi']) as $com)
      {
         $com->refcombinacion = NULL;
         if($_POST['refcombinacion'])
         {
            $com->refcombinacion = $_POST['refcombinacion'];
         }
         
         $com->codbarras = NULL;
         if($_POST['codbarras'])
         {
            $com->codbarras = $_POST['codbarras'];
         }
         
         $com->impactoprecio = floatval($_POST['impactoprecio']);
         $com->stockfis = floatval($_POST['stockcombinacion']);
         $com->save();
      }
      
      $this->new_message('Combinación modificada.');
   }
   
   private function eliminar_combinacion()
   {
      $comb1 = new articulo_combinacion();
      foreach($comb1->all_from_codigo($_GET['delete_combi']) as $com)
      {
         $com->delete();
      }
      
      $this->new_message('Combinación eliminada.');
   }
   
   public function get_tarifas()
   {
      $tarlist = array();
      $tarifa = new tarifa();
      
      foreach($tarifa->all() as $tar)
      {
         $articulo = $this->articulo->get($this->articulo->referencia);
         if($articulo)
         {
            $articulo->dtopor = 0;
            $aux = array($articulo);
            $tar->set_precios($aux);
            $tarlist[] = $aux[0];
         }
      }
      
      return $tarlist;
   }
   
   public function get_articulo_proveedores()
   {
      $artprov = new articulo_proveedor();
      $alist = $artprov->all_from_ref($this->articulo->referencia);
      
      /// revismos el impuesto y la descripción
      foreach($alist as $i => $value)
      {
         $guardar = FALSE;
         if( is_null($value->codimpuesto) )
         {
            $alist[$i]->codimpuesto = $this->articulo->codimpuesto;
            $guardar = TRUE;
         }
         
         if( is_null($value->descripcion) )
         {
            $alist[$i]->descripcion = $this->articulo->descripcion;
            $guardar = TRUE;
         }
         
         if($guardar)
         {
            $alist[$i]->save();
         }
      }
      
      return $alist;
   }
   
   /**
    * Devuelve un array con los movimientos de stock del artículo.
    * @return array
    */
   public function get_movimientos($codalmacen)
   {
      $mlist = array();
      
      if( !isset($this->regularizaciones) )
      {
         $reg = new regularizacion_stock();
         $this->regularizaciones = $reg->all_from_articulo($this->articulo->referencia);
      }
      
      foreach($this->regularizaciones as $reg)
      {
         //Solo tomamos las regularizaciones del almacén actual
         if ($reg->codalmacendest == $codalmacen)
         {
            $mlist[] = array(
               'codalmacen' => $reg->codalmacendest,
               'origen' => 'Regularización',
               'url' => '#stock',
               'inicial' => $reg->cantidadini,
               'movimiento' => '-',
               'final' => $reg->cantidadfin,
               'fecha' => $reg->fecha,
               'hora' => $reg->hora
            );
         }
      }
      
      /// nos guardamos la lista de tablas para agilizar
      $tablas = $this->db->list_tables();
      
      if( $this->db->table_exists('albaranesprov', $tablas) AND $this->db->table_exists('lineasalbaranesprov', $tablas) )
      {
         /// buscamos el artículo en albaranes de compra
         $sql = "SELECT a.idalbaran,a.codigo,l.cantidad,a.fecha,a.hora,a.codalmacen
            FROM albaranesprov a, lineasalbaranesprov l
            WHERE a.idalbaran = l.idalbaran
            AND a.codalmacen = ".$this->empresa->var2str($codalmacen)." 
            AND l.referencia = ".$this->articulo->var2str($this->articulo->referencia);
         
         $data = $this->db->select($sql);
         if($data)
         {
            foreach($data as $d)
            {
               $mlist[] = array(
                   'codalmacen' => $d['codalmacen'],
                   'origen' => ucfirst(FS_ALBARAN).' compra '.$d['codigo'],
                   'url' => 'index.php?page=compras_albaran&id='.intval($d['idalbaran']),
                   'inicial' => 0,
                   'movimiento' => floatval($d['cantidad']),
                   'final' => 0,
                   'fecha' => date('d-m-Y', strtotime($d['fecha'])),
                   'hora' => date('H:i:s', strtotime($d['hora']))
               );
            }
         }
      }
      
      if( $this->db->table_exists('facturasprov', $tablas) AND $this->db->table_exists('lineasfacturasprov', $tablas) )
      {
         /// buscamos el artículo en facturas de compra
         $sql = "SELECT f.idfactura,f.codigo,l.cantidad,f.fecha,f.hora,f.codalmacen
            FROM facturasprov f, lineasfacturasprov l
            WHERE f.idfactura = l.idfactura AND l.idalbaran IS NULL
            AND f.codalmacen = ".$this->empresa->var2str($codalmacen)." 
            AND l.referencia = ".$this->articulo->var2str($this->articulo->referencia);
         
         $data = $this->db->select($sql);
         if($data)
         {
            foreach($data as $d)
            {
               $mlist[] = array(
                   'codalmacen' => $d['codalmacen'],
                   'origen' => 'Factura compra '.$d['codigo'],
                   'url' => 'index.php?page=compras_factura&id='.intval($d['idfactura']),
                   'inicial' => 0,
                   'movimiento' => floatval($d['cantidad']),
                   'final' => 0,
                   'fecha' => date('d-m-Y', strtotime($d['fecha'])),
                   'hora' => date('H:i:s', strtotime($d['hora']))
               );
            }
         }
      }
      
      if( $this->db->table_exists('albaranescli', $tablas) AND $this->db->table_exists('lineasalbaranescli', $tablas) )
      {
         /// buscamos el artículo en albaranes de venta
         $sql = "SELECT a.idalbaran,a.codigo,l.cantidad,a.fecha,a.hora,a.codalmacen
            FROM albaranescli a, lineasalbaranescli l
            WHERE a.idalbaran = l.idalbaran
            AND a.codalmacen = ".$this->empresa->var2str($codalmacen)." 
            AND l.referencia = ".$this->articulo->var2str($this->articulo->referencia);
         
         $data = $this->db->select($sql);
         if($data)
         {
            foreach($data as $d)
            {
               $mlist[] = array(
                   'codalmacen' => $d['codalmacen'],
                   'origen' => ucfirst(FS_ALBARAN).' venta '.$d['codigo'],
                   'url' => 'index.php?page=ventas_albaran&id='.intval($d['idalbaran']),
                   'inicial' => 0,
                   'movimiento' => 0-floatval($d['cantidad']),
                   'final' => 0,
                   'fecha' => date('d-m-Y', strtotime($d['fecha'])),
                   'hora' => date('H:i:s', strtotime($d['hora']))
               );
            }
         }
      }
      
      if( $this->db->table_exists('facturascli', $tablas) AND $this->db->table_exists('lineasfacturascli', $tablas) )
      {
         /// buscamos el artículo en facturas de venta
         $sql = "SELECT f.idfactura,f.codigo,l.cantidad,f.fecha,f.hora,f.codalmacen
            FROM facturascli f, lineasfacturascli l
            WHERE f.idfactura = l.idfactura AND l.idalbaran IS NULL
            AND f.codalmacen = ".$this->empresa->var2str($codalmacen)." 
            AND l.referencia = ".$this->articulo->var2str($this->articulo->referencia);
         
         $data = $this->db->select($sql);
         if($data)
         {
            foreach($data as $d)
            {
               $mlist[] = array(
                   'codalmacen' => $d['codalmacen'],
                   'origen' => 'Factura venta '.$d['codigo'],
                   'url' => 'index.php?page=ventas_factura&id='.intval($d['idfactura']),
                   'inicial' => 0,
                   'movimiento' => 0-floatval($d['cantidad']),
                   'final' => 0,
                   'fecha' => date('d-m-Y', strtotime($d['fecha'])),
                   'hora' => date('H:i:s', strtotime($d['hora']))
               );
            }
         }
      }
      
      /// ordenamos por fecha y hora
      usort($mlist, function($a,$b) {
         if( strtotime($a['fecha'].' '.$a['hora']) == strtotime($b['fecha'].' '.$b['hora']) )
         {
            return 0;
         }
         else if( strtotime($a['fecha'].' '.$a['hora']) < strtotime($b['fecha'].' '.$b['hora']) )
         {
            return -1;
         }
         else
            return 1;
      });
            
      /// recalculamos las cantidades finales hacia atrás
      $final = $this->stock->total_from_articulo($this->articulo->referencia,$codalmacen);
      for($i = count($mlist) - 1; $i >= 0; $i--)
      {
         if($mlist[$i]['movimiento'] == '-')
         {
            if($mlist[$i]['inicial'] < $mlist[$i]['final'])
            {
               /// entrada de stock
               $mlist[$i]['movimiento'] =  $mlist[$i]['final'] - $mlist[$i]['inicial'];
            }
            else
            {
               //El resultado del stock final anterior y el valor de la regularización se agrega como salida o ingreso
               $mlist[$i]['movimiento'] = $mlist[$i]['final'] - $mlist[$i]['inicial'];
            }
         }
         $mlist[$i]['final'] = $final;
         $final -= $mlist[$i]['movimiento'];
         $mlist[$i]['inicial'] = $final;
      }
      
      /// Si esta el agrupar con un valor se agrupan los datos
      if($this->agrupar)
      {
         foreach($mlist as $item)
         {
            if( !isset($this->mgrupo[$item['fecha']]) )
            {
               $this->mgrupo[$item['fecha']]['ingreso'] = FALSE;
               $this->mgrupo[$item['fecha']]['salida'] = FALSE;
            }
            
            if($item['movimiento'] > 0)
            {
               $this->mgrupo[$item['fecha']]['ingreso'] += $item['movimiento'];
            }
            else if($item['movimiento'] < 0)
            {
               $this->mgrupo[$item['fecha']]['salida'] += $item['movimiento'];
            }
         }
      }
      
      return $mlist;
   }
   
   /**
    * Calcula el stock real del artículo en función de los movimientos y regularizaciones
    */
   private function calcular_stock_real()
   {
      $almacenes = $this->almacen->all();
      foreach($almacenes as $alm)
      {
         $movimientos = $this->get_movimientos($alm->codalmacen);
         $total = 0;
         foreach($movimientos as $mov)
         {
            if($mov['codalmacen'] == $alm->codalmacen)
            {
               $total += $mov['movimiento'];
            }
         }
         
         if( $this->articulo->set_stock($alm->codalmacen, $total) )
         {
            $this->new_message('Recarculado el stock del almacén '.$alm->codalmacen.'.');
         }
         else
         {
            $this->new_error_msg('Error al recarcular el stock del almacén '.$alm->codalmacen.'.');
         }
      }
      $this->new_message("Stock actualizado correctamente para el artículo ".$this->articulo->descripcion);
      $this->new_message("Puedes recalcular el stock de todos los artículos desde"
               . " <b>Informes &gt; Artículos &gt; Stock</b>");
   }
   
   public function combinaciones()
   {
      $lista = array();
      
      $comb1 = new articulo_combinacion();
      foreach($comb1->all_from_ref($this->articulo->referencia) as $com)
      {
         if( isset($lista[$com->codigo]) )
         {
            $lista[$com->codigo]->txt .= ', '.$com->nombreatributo.' - '.$com->valor;
         }
         else
         {
            $com->txt = $com->nombreatributo.' - '.$com->valor;
            $lista[$com->codigo] = $com;
         }
      }
      
      return $lista;
   }
   
   public function atributos()
   {
      $atri0 = new atributo();
      return $atri0->all();
   }
}
