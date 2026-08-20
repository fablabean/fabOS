<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\RiskFamily;
use Illuminate\Database\Seeder;

/**
 * La escalera de formación del laboratorio (§9).
 *
 * SON UN PUNTO DE PARTIDA. Los nombres, las horas y sobre todo **qué habilita
 * cada curso** los decide la coordinación: aquí quedan propuestos para que el
 * módulo funcione completo desde el primer día y para que se vea concreto lo
 * que hay que ajustar.
 *
 * El criterio que usé para asignar familias de riesgo: un curso habilita lo que
 * de verdad se practica en él. Un bit de inducción general no abre ninguna
 * máquina —enseña a moverse por el laboratorio—, y a partir de byte cada curso
 * abre lo suyo.
 *
 * El seeder no pisa lo que ya existe: un curso reescrito a mano no se revierte.
 */
class CourseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->cursos() as $datos) {
            $familias = $datos['familias'];
            unset($datos['familias']);

            $curso = Course::firstOrCreate(['slug' => $datos['slug']], $datos);

            // Solo se asignan si el curso nace ahora: si alguien ya ajustó qué
            // habilita, volver a sembrar no debe deshacerlo.
            if ($curso->wasRecentlyCreated && $familias) {
                $curso->riskFamilies()->sync(
                    RiskFamily::whereIn('slug', $familias)->pluck('id')->all()
                );
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function cursos(): array
    {
        return [
            [
                'slug'  => 'bit-induccion',
                'name'  => 'bit · Inducción al laboratorio',
                'level' => 'bit',
                'hours' => 2,
                'summary' => 'Cómo funciona el Fab Lab: espacios, reglas de seguridad, reservas y qué se puede hacer aquí.',
                'requirements' => 'Ninguno. Es el primer paso para todo el mundo.',
                'description' => 'Un recorrido por el laboratorio y por el sistema: cómo se reserva, cómo se pide acompañamiento y qué significa habilitarse en una máquina. No abre ninguna máquina por sí solo: es el punto de partida.',
                'familias' => [],
            ],
            [
                'slug'  => 'byte-impresion-3d',
                'name'  => 'byte · Impresión 3D',
                'level' => 'byte',
                'hours' => 8,
                'summary' => 'Del modelo al objeto: laminado, materiales, primera capa y qué hacer cuando algo falla.',
                'requirements' => 'Haber hecho la inducción (bit).',
                'familias' => ['fdm'],
            ],
            [
                'slug'  => 'byte-corte-laser',
                'name'  => 'byte · Corte láser',
                'level' => 'byte',
                'hours' => 8,
                'summary' => 'Preparar un archivo de corte, elegir material y operar la máquina con seguridad.',
                'requirements' => 'Haber hecho la inducción (bit).',
                'familias' => ['co2', 'corte-vinilo'],
            ],
            [
                'slug'  => 'kilo-resina-y-acabados',
                'name'  => 'kilo · Resina y acabados',
                'level' => 'kilo',
                'hours' => 12,
                'summary' => 'Impresión en resina, lavado, curado y acabados. Manejo de químicos y protección personal.',
                'requirements' => 'byte de impresión 3D.',
                'familias' => ['resina', 'post', 'acabados'],
            ],
            [
                'slug'  => 'kilo-electronica',
                'name'  => 'kilo · Electrónica y soldadura',
                'level' => 'kilo',
                'hours' => 12,
                'summary' => 'Diseño de circuitos, soldadura y programación de microcontroladores.',
                'requirements' => 'Haber hecho la inducción (bit).',
                'familias' => ['electronica'],
            ],
            [
                'slug'  => 'mega-cnc',
                'name'  => 'mega · Fresado CNC',
                'level' => 'mega',
                'hours' => 20,
                'summary' => 'CAM, sujeción, ceros, velocidades y avances. La máquina más exigente del laboratorio.',
                'requirements' => 'kilo en alguna área de fabricación y proyecto propio en curso.',
                'familias' => ['cnc-escritorio'],
            ],
            [
                'slug'  => 'giga-acompanamiento',
                'name'  => 'giga · Acompañar a otros',
                'level' => 'giga',
                'hours' => 16,
                'summary' => 'Formación para quienes acompañan a otras personas en el laboratorio: seguridad, pedagogía y criterio.',
                'requirements' => 'kilo o mega en el área que va a acompañar.',
                'familias' => ['maquina-mayor', 'herramienta-menor'],
            ],
            [
                'slug'  => 'tera-fab-academy',
                'name'  => 'tera · Fab Academy',
                'level' => 'tera',
                'hours' => 500,
                'summary' => 'El programa completo de la Fab Foundation. Seis meses, un proyecto final y acceso autónomo a todo el laboratorio.',
                'requirements' => 'Admisión al programa. El Ean Fablab es el único laboratorio acreditado en Colombia.',
                'familias' => ['fdm', 'resina', 'co2', 'cnc-escritorio', 'electronica', 'impresion-uv'],
            ],
        ];
    }
}
