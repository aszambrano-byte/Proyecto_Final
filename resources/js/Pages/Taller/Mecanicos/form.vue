<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import {
    ayudaDocumento,
    documentoNumerico,
    longitudDocumento,
    normalizarDocumento,
    normalizarTelefono,
    tiposDocumentoColombia,
} from "../../../utils/validation";

interface Horario {
    diaSemana: number;
    horaInicio: string;
    horaFin: string;
}
interface Mecanico {
    id: string;
    usuarioId?: string;
    tipoDocumento: string;
    numeroDocumento: string;
    nombres: string;
    apellidos: string;
    telefono: string;
    email: string;
    fechaIngreso?: string;
    especialidadIds: string[];
    horarios: Horario[];
}
const props = defineProps<{
    mecanico: Mecanico | null;
    especialidades: { label: string; value: string }[];
    usuarios: { label: string; value: string }[];
}>();
const dias = [
    "Lunes",
    "Martes",
    "Miércoles",
    "Jueves",
    "Viernes",
    "Sábado",
    "Domingo",
];
const tiposDocumento = tiposDocumentoColombia.filter(
    (tipo) => tipo.value !== "NIT",
);
const state = reactive({
    usuarioId: props.mecanico?.usuarioId ?? "",
    tipoDocumento: props.mecanico?.tipoDocumento ?? "CC",
    numeroDocumento: props.mecanico?.numeroDocumento ?? "",
    nombres: props.mecanico?.nombres ?? "",
    apellidos: props.mecanico?.apellidos ?? "",
    telefono: props.mecanico?.telefono ?? "",
    email: props.mecanico?.email ?? "",
    fechaIngreso: props.mecanico?.fechaIngreso ?? "",
    especialidadIds: [...(props.mecanico?.especialidadIds ?? [])],
    horarios: dias.map((_, i) => {
        const h = props.mecanico?.horarios.find((x) => x.diaSemana === i + 1);
        return {
            diaSemana: i + 1,
            activo: !!h || (!props.mecanico && i < 6),
            horaInicio: h?.horaInicio ?? "08:00",
            horaFin: h?.horaFin ?? (i === 5 ? "13:00" : "17:00"),
        };
    }),
});
const errors = computed<Record<string, string>>(
    () => usePage().props.errors as Record<string, string>,
);
const procesando = ref(false);
function toggleEspecialidad(id: string, checked: boolean) {
    if (checked && !state.especialidadIds.includes(id))
        state.especialidadIds.push(id);
    if (!checked)
        state.especialidadIds = state.especialidadIds.filter((x) => x !== id);
}
function aplicarHorarioTaller() {
    state.horarios.forEach((h, i) =>
        Object.assign(h, {
            activo: i < 6,
            horaInicio: "08:00",
            horaFin: i === 5 ? "13:00" : "17:00",
        }),
    );
}
function limpiarHorario() {
    state.horarios.forEach((h) => {
        h.activo = false;
    });
}
function guardar() {
    procesando.value = true;
    const data = {
        ...state,
        horarios: state.horarios
            .filter((h) => h.activo)
            .map(({ diaSemana, horaInicio, horaFin }) => ({
                diaSemana,
                horaInicio,
                horaFin,
            })),
    };
    const options = {
        onFinish: () => {
            procesando.value = false;
        },
    };
    props.mecanico
        ? router.put(
              route("mecanicos.update", props.mecanico.id),
              data,
              options,
          )
        : router.post(route("mecanicos.store"), data, options);
}
</script>
<template>
    <Head :title="mecanico ? 'Editar mecánico' : 'Nuevo mecánico'" />
    <UDashboardPanel
        ><template #header
            ><UDashboardNavbar
                :title="mecanico ? 'Editar mecánico' : 'Nuevo mecánico'"
                ><template #leading
                    ><UDashboardSidebarCollapse /></template></UDashboardNavbar></template
        ><template #body
            ><form
                class="mx-auto max-w-5xl space-y-6"
                @submit.prevent="guardar"
            >
                <UCard
                    ><template #header
                        ><h2 class="font-semibold">
                            Información personal
                        </h2></template
                    >
                    <div class="grid gap-5 md:grid-cols-2">
                        <UFormField
                            class="md:col-span-2"
                            label="Cuenta de acceso"
                            hint="Opcional. Solo aparecen usuarios activos con rol Mecánico."
                            :error="errors.usuarioId"
                            ><USelect
                                v-model="state.usuarioId"
                                class="w-full"
                                :items="usuarios"
                                placeholder="Sin cuenta vinculada" /></UFormField
                        ><UFormField
                            label="Tipo de documento"
                            required
                            :error="errors.tipoDocumento"
                            ><USelect
                                v-model="state.tipoDocumento"
                                class="w-full"
                                :items="tiposDocumento" /></UFormField
                        ><UFormField
                            label="Número de documento"
                            required
                            :hint="ayudaDocumento(state.tipoDocumento)"
                            :error="errors.numeroDocumento"
                            ><UInput
                                v-model="state.numeroDocumento"
                                class="w-full"
                                :inputmode="
                                    documentoNumerico(state.tipoDocumento)
                                        ? 'numeric'
                                        : 'text'
                                "
                                :maxlength="
                                    longitudDocumento(state.tipoDocumento)
                                "
                                required
                                @update:model-value="
                                    state.numeroDocumento = normalizarDocumento(
                                        String($event),
                                        state.tipoDocumento,
                                    )
                                " /></UFormField
                        ><UFormField
                            label="Nombres"
                            required
                            :error="errors.nombres"
                            ><UInput
                                v-model="state.nombres"
                                maxlength="120"
                                required
                                class="w-full" /></UFormField
                        ><UFormField
                            label="Apellidos"
                            required
                            :error="errors.apellidos"
                            ><UInput
                                v-model="state.apellidos"
                                maxlength="120"
                                required
                                class="w-full" /></UFormField
                        ><UFormField
                            label="Correo"
                            required
                            :error="errors.email"
                            ><UInput
                                v-model="state.email"
                                type="email"
                                autocomplete="email"
                                maxlength="254"
                                required
                                class="w-full" /></UFormField
                        ><UFormField
                            label="Teléfono"
                            required
                            hint="Número ecuatoriano móvil de 10 dígitos, fijo de 9 o con prefijo +593."
                            :error="errors.telefono"
                            ><UInput
                                v-model="state.telefono"
                                type="tel"
                                inputmode="tel"
                                autocomplete="tel"
                                maxlength="13"
                                placeholder="0991234567"
                                required
                                class="w-full"
                                @update:model-value="
                                    state.telefono = normalizarTelefono(
                                        String($event),
                                    )
                                " /></UFormField
                        ><UFormField
                            label="Fecha de ingreso"
                            hint="Opcional"
                            :error="errors.fechaIngreso"
                            ><UInput
                                v-model="state.fechaIngreso"
                                type="date"
                                class="w-full"
                        /></UFormField></div
                ></UCard>
                <UCard
                    ><template #header
                        ><div>
                            <h2 class="font-semibold">Especialidades</h2>
                            <p class="text-sm text-muted">
                                Selecciona al menos una.
                            </p>
                        </div></template
                    >
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <UCheckbox
                            v-for="e in especialidades"
                            :key="e.value"
                            :model-value="
                                state.especialidadIds.includes(e.value)
                            "
                            :label="e.label"
                            @update:model-value="
                                toggleEspecialidad(e.value, !!$event)
                            "
                        />
                    </div>
                    <p
                        v-if="errors.especialidadIds"
                        class="mt-3 text-sm text-error"
                    >
                        {{ errors.especialidadIds }}
                    </p></UCard
                >
                <UCard
                    ><template #header
                        ><div
                            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div>
                                <h2 class="font-semibold">
                                    Disponibilidad semanal
                                </h2>
                                <p class="text-sm text-muted">
                                    Estos días y horas determinan los turnos
                                    ofrecidos al agendar citas.
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <UButton
                                    type="button"
                                    label="Horario del taller"
                                    icon="i-lucide-clock-3"
                                    size="sm"
                                    color="neutral"
                                    variant="outline"
                                    @click="aplicarHorarioTaller"
                                /><UButton
                                    type="button"
                                    label="Limpiar"
                                    size="sm"
                                    color="neutral"
                                    variant="ghost"
                                    @click="limpiarHorario"
                                />
                            </div></div
                    ></template>
                    <div class="space-y-3">
                        <div
                            v-for="(h, i) in state.horarios"
                            :key="h.diaSemana"
                            class="grid items-center gap-3 rounded-lg border border-default p-3 sm:grid-cols-[140px_1fr_1fr]"
                        >
                            <UCheckbox
                                v-model="h.activo"
                                :label="dias[i]"
                            /><UInput
                                v-model="h.horaInicio"
                                type="time"
                                :disabled="!h.activo"
                            /><UInput
                                v-model="h.horaFin"
                                type="time"
                                :disabled="!h.activo"
                            />
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-muted">
                        Plantilla sugerida: lunes a viernes de 08:00 a 17:00 y
                        sábado de 08:00 a 13:00. Puedes personalizar cada
                        mecánico.
                    </p>
                    <p v-if="errors.horarios" class="mt-3 text-sm text-error">
                        {{ errors.horarios }}
                    </p></UCard
                >
                <div
                    class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
                >
                    <UButton
                        type="button"
                        label="Cancelar"
                        color="neutral"
                        variant="outline"
                        @click="router.visit(route('mecanicos.index'))"
                    /><UButton
                        type="submit"
                        label="Guardar mecánico"
                        icon="i-lucide-save"
                        :loading="procesando"
                    />
                </div></form></template
    ></UDashboardPanel>
</template>
