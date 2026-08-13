<?php

/*
|--------------------------------------------------------------------------
| Mensajes de validación en español
|--------------------------------------------------------------------------
|
| No existían. La aplicación corre con `app.locale = es` y `fallback_locale = es`,
| así que cuando una regla fallaba no había ni traducción ni respaldo en inglés: el
| usuario veía literalmente «validation.max.string» y no tenía manera de saber qué
| campo estaba mal ni por qué.
|
| Están todas las reglas de Laravel, no solo las que el sistema usa hoy: cuesta lo
| mismo y evita que la próxima regla que alguien escriba vuelva a mostrar una clave
| cruda en la cara de un cliente.
|
| Español colombiano neutro, sin voseo, igual que el resto del producto.
|
*/

return [
    'accepted'             => 'Debes aceptar el campo :attribute.',
    'accepted_if'          => 'Debes aceptar el campo :attribute cuando :other es :value.',
    'active_url'           => 'El campo :attribute no es una URL válida.',
    'after'                => 'El campo :attribute debe ser una fecha posterior a :date.',
    'after_or_equal'       => 'El campo :attribute debe ser una fecha igual o posterior a :date.',
    'alpha'                => 'El campo :attribute solo puede tener letras.',
    'alpha_dash'           => 'El campo :attribute solo puede tener letras, números, guiones y guiones bajos.',
    'alpha_num'            => 'El campo :attribute solo puede tener letras y números.',
    'any_of'               => 'El valor del campo :attribute no es válido.',
    'array'                => 'El valor del campo :attribute debe ser una lista.',
    'ascii'                => 'El campo :attribute solo puede tener caracteres y símbolos de un byte.',
    'before'               => 'El campo :attribute debe ser una fecha anterior a :date.',
    'before_or_equal'      => 'El campo :attribute debe ser una fecha igual o anterior a :date.',

    'between' => [
        'array'   => 'Se necesitan entre :min y :max elementos en el campo :attribute.',
        'file'    => 'El campo :attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string'  => 'El campo :attribute debe tener entre :min y :max caracteres.',
    ],

    'boolean'              => 'El valor del campo :attribute solo puede ser verdadero o falso.',
    'can'                  => 'Hay un valor no permitido en el campo :attribute.',
    'confirmed'            => 'El campo :attribute no coincide con su confirmación.',
    'contains'             => 'Al campo :attribute le falta un valor obligatorio.',
    'current_password'     => 'La contraseña no es correcta.',
    'date'                 => 'El campo :attribute no es una fecha válida.',
    'date_equals'          => 'El campo :attribute debe ser una fecha igual a :date.',
    'date_format'          => 'El campo :attribute no corresponde al formato :format.',
    'decimal'              => 'El campo :attribute debe tener :decimal decimales.',
    'declined'             => 'Debes rechazar el campo :attribute.',
    'declined_if'          => 'Debes rechazar el campo :attribute cuando :other es :value.',
    'different'            => 'El campo :attribute y :other deben ser distintos.',
    'digits'               => 'El campo :attribute debe tener :digits dígitos.',
    'digits_between'       => 'El campo :attribute debe tener entre :min y :max dígitos.',
    'dimensions'           => 'Las dimensiones del campo :attribute no son válidas.',
    'distinct'             => 'Hay un valor repetido en el campo :attribute.',
    'doesnt_contain'       => 'El campo :attribute no puede contener ninguno de estos valores: :values.',
    'doesnt_end_with'      => 'El campo :attribute no puede terminar con: :values.',
    'doesnt_start_with'    => 'El campo :attribute no puede empezar con: :values.',
    'email'                => 'El campo :attribute no es un correo válido.',
    'ends_with'            => 'El campo :attribute debe terminar con: :values.',
    'enum'                 => 'El valor del campo :attribute no está entre los permitidos.',
    'exists'               => 'El valor del campo :attribute no existe.',
    'extensions'           => 'El campo :attribute debe tener una de estas extensiones: :values.',
    'file'                 => 'El campo :attribute debe ser un archivo.',
    'filled'               => 'Debes indicar el campo :attribute.',

    'gt' => [
        'array'   => 'El campo :attribute debe tener más de :value elementos.',
        'file'    => 'El campo :attribute debe pesar más de :value kilobytes.',
        'numeric' => 'El campo :attribute debe ser mayor que :value.',
        'string'  => 'El campo :attribute debe tener más de :value caracteres.',
    ],

    'gte' => [
        'array'   => 'El campo :attribute debe tener :value elementos o más.',
        'file'    => 'El campo :attribute debe pesar :value kilobytes o más.',
        'numeric' => 'El campo :attribute debe ser mayor o igual que :value.',
        'string'  => 'El campo :attribute debe tener :value caracteres o más.',
    ],

    'hex_color'            => 'El campo :attribute debe ser un color hexadecimal válido.',
    'image'                => 'El campo :attribute debe ser una imagen.',
    'in'                   => 'El valor del campo :attribute no está entre los permitidos.',
    'in_array'             => 'El campo :attribute no existe en :other.',
    'integer'              => 'El valor del campo :attribute debe ser un número entero.',
    'ip'                   => 'El campo :attribute debe ser una dirección IP válida.',
    'ipv4'                 => 'El campo :attribute debe ser una dirección IPv4 válida.',
    'ipv6'                 => 'El campo :attribute debe ser una dirección IPv6 válida.',
    'json'                 => 'El campo :attribute debe ser un JSON válido.',
    'list'                 => 'El valor del campo :attribute debe ser una lista.',
    'lowercase'            => 'El campo :attribute debe estar en minúsculas.',

    'lt' => [
        'array'   => 'El campo :attribute debe tener menos de :value elementos.',
        'file'    => 'El campo :attribute debe pesar menos de :value kilobytes.',
        'numeric' => 'El campo :attribute debe ser menor que :value.',
        'string'  => 'El campo :attribute debe tener menos de :value caracteres.',
    ],

    'lte' => [
        'array'   => 'El campo :attribute no puede tener más de :value elementos.',
        'file'    => 'El campo :attribute debe pesar :value kilobytes o menos.',
        'numeric' => 'El campo :attribute debe ser menor o igual que :value.',
        'string'  => 'El campo :attribute debe tener :value caracteres o menos.',
    ],

    'mac_address'          => 'El campo :attribute debe ser una dirección MAC válida.',

    'max' => [
        'array'   => 'No se permiten más de :max elementos en el campo :attribute.',
        'file'    => 'El campo :attribute no puede pesar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string'  => 'El campo :attribute no puede tener más de :max caracteres.',
    ],

    'max_digits'           => 'El campo :attribute no puede tener más de :max dígitos.',
    'mimes'                => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'mimetypes'            => 'El campo :attribute debe ser un archivo de tipo: :values.',

    'min' => [
        'array'   => 'Se necesitan al menos :min elementos en el campo :attribute.',
        'file'    => 'El campo :attribute debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string'  => 'El campo :attribute debe tener al menos :min caracteres.',
    ],

    'min_digits'           => 'El campo :attribute debe tener al menos :min dígitos.',
    'missing'              => 'No debe venir el campo :attribute.',
    'missing_if'           => 'El campo :attribute no debe venir cuando :other es :value.',
    'missing_unless'       => 'El campo :attribute no debe venir salvo que :other sea :value.',
    'missing_with'         => 'El campo :attribute no debe venir si viene :values.',
    'missing_with_all'     => 'El campo :attribute no debe venir si vienen :values.',
    'multiple_of'          => 'El campo :attribute debe ser múltiplo de :value.',
    'not_in'               => 'El valor del campo :attribute no está permitido.',
    'not_regex'            => 'El formato del campo :attribute no es válido.',
    'numeric'              => 'El valor del campo :attribute debe ser un número.',

    'password' => [
        'letters'       => 'La contraseña debe tener al menos una letra.',
        'mixed'         => 'La contraseña debe tener al menos una mayúscula y una minúscula.',
        'numbers'       => 'La contraseña debe tener al menos un número.',
        'symbols'       => 'La contraseña debe tener al menos un símbolo.',
        'uncompromised' => 'Esta contraseña apareció en una filtración de datos. Elige otra.',
    ],

    'present'              => 'Debes indicar el campo :attribute.',
    'present_if'           => 'Debes indicar el campo :attribute cuando :other es :value.',
    'present_unless'       => 'Debes indicar el campo :attribute salvo que :other sea :value.',
    'present_with'         => 'Debes indicar el campo :attribute si viene :values.',
    'present_with_all'     => 'Debes indicar el campo :attribute si vienen :values.',
    'prohibited'           => 'No se permite enviar el campo :attribute.',
    'prohibited_if'        => 'No se permite enviar el campo :attribute cuando :other es :value.',
    'prohibited_if_accepted' => 'No se permite enviar el campo :attribute cuando se acepta :other.',
    'prohibited_if_declined' => 'No se permite enviar el campo :attribute cuando se rechaza :other.',
    'prohibited_unless'    => 'No se permite enviar el campo :attribute salvo que :other esté entre :values.',
    'prohibits'            => 'El campo :attribute no permite que venga el campo :other.',
    'regex'                => 'El formato del campo :attribute no es válido.',
    'required'             => 'Debes indicar el campo :attribute.',
    'required_array_keys'  => 'Al campo :attribute le faltan estos valores: :values.',
    'required_if'          => 'Debes indicar el campo :attribute cuando :other es :value.',
    'required_if_accepted' => 'Debes indicar el campo :attribute cuando se acepta :other.',
    'required_if_declined' => 'Debes indicar el campo :attribute cuando se rechaza :other.',
    'required_unless'      => 'Debes indicar el campo :attribute salvo que :other esté entre :values.',
    'required_with'        => 'Debes indicar el campo :attribute cuando viene :values.',
    'required_with_all'    => 'Debes indicar el campo :attribute cuando vienen :values.',
    'required_without'     => 'Debes indicar el campo :attribute cuando no viene :values.',
    'required_without_all' => 'Debes indicar el campo :attribute cuando no viene ninguno de :values.',
    'same'                 => 'El campo :attribute y :other deben coincidir.',

    'size' => [
        'array'   => 'Se necesitan :size elementos en el campo :attribute.',
        'file'    => 'El campo :attribute debe pesar :size kilobytes.',
        'numeric' => 'El campo :attribute debe ser :size.',
        'string'  => 'El campo :attribute debe tener :size caracteres.',
    ],

    'starts_with'          => 'El campo :attribute debe empezar con: :values.',
    'string'               => 'El valor del campo :attribute debe ser texto.',
    'timezone'             => 'El campo :attribute debe ser una zona horaria válida.',
    'unique'               => 'El campo :attribute ya está en uso.',
    'uploaded'             => 'No se pudo subir el campo :attribute.',
    'uppercase'            => 'El campo :attribute debe estar en mayúsculas.',
    'url'                  => 'El campo :attribute no es una URL válida.',
    'ulid'                 => 'El campo :attribute debe ser un ULID válido.',
    'uuid'                 => 'El campo :attribute debe ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Nombres de los campos
    |--------------------------------------------------------------------------
    |
    | Sin esto, el mensaje dice «descripcion_corta no puede tener más de 1000
    | caracteres»: el nombre de la columna, que no es como se llama el campo en la
    | pantalla. Están los que el usuario ve, no todas las columnas del sistema.
    |
    */

    'attributes' => [
        'nombre'                => 'nombre',
        'referencia'            => 'referencia',
        'descripcion_corta'     => 'descripción corta',
        'descripcion_larga'     => 'descripción larga',
        'unidad_medida'         => 'unidad de medida',
        'categoria_id'          => 'categoría',
        'proveedor_id'          => 'proveedor',
        'precio_costo'          => 'precio de costo',
        'stock_minimo'          => 'stock mínimo',
        'stock_maximo'          => 'stock máximo',
        'email'                 => 'correo',
        'password'              => 'contraseña',
        'telefono'              => 'teléfono',
        'direccion'             => 'dirección',
        'documento'             => 'documento',
        'cliente_id'            => 'cliente',
        'sede_id'               => 'sede',
        'bodega_id'             => 'bodega',
        'cantidad'              => 'cantidad',
        'fecha'                 => 'fecha',
        'fecha_entrega'         => 'fecha de entrega',
        'observaciones'         => 'observaciones',
        'notas'                 => 'notas',
        'etiqueta'              => 'etiqueta',
        'clave'                 => 'clave',
        'valor'                 => 'valor',
        'margen_pct'            => 'margen',
        'precio'                => 'precio',
        'imagenes'              => 'imágenes',
        'archivo'               => 'archivo',
        'aporte_descripcion'    => 'qué es y para qué sirve',
        'aporte_caracteristicas' => 'características técnicas',
        'aporte_ventajas'       => 'ventajas',
        'aporte_beneficios'     => 'beneficios',
        'aporte_componentes'    => 'componentes',
    ],
];
