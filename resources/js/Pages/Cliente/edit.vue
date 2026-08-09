<script setup lang="ts">
import { reactive, ref, computed, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import type { Cliente } from "../../types";
import {
    ayudaDocumento,
    documentoNumerico,
    longitudDocumento,
    normalizarDocumento,
    normalizarTelefono,
    tiposDocumentoColombia,
} from "../../utils/validation";

const props = defineProps<{
    cliente: Cliente;
}>();

// Estado del formulario con datos del cliente
const state = reactive({
    tipoDocumento: props.cliente.tipoDocumento,
    numeroDocumento: props.cliente.numeroDocumento,
    razonSocial: props.cliente.razonSocial,
    direccion: props.cliente.direccion,
    telefono: props.cliente.telefono,
    email: props.cliente.email,
});
watch(
    () => state.tipoDocumento,
    (tipo) => {
        state.numeroDocumento = normalizarDocumento(
            state.numeroDocumento,
            tipo,
        );
    },
);

// Obtener errores de validación del backend
const page = usePage();
const backendErrors = computed(() => page.props.errors || {});

// Convertir errores de array a string (Laravel retorna arrays)
const errors = computed(() => {
    const result: Record<string, string> = {};
    Object.keys(backendErrors.value).forEach((key) => {
        const error = backendErrors.value[key];
        result[key] = Array.isArray(error) ? error[0] : error;
    });
    return result;
});
// Loading state
const isLoading = ref(false);

// Submit handler
const handleSubmit = () => {
    isLoading.value = true;

    router.put(route("clientes.update", props.cliente.id), state, {
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const handleCancel = () => {
    router.visit(route("clientes.index"));
};
</script>

<template>
    <UDashboardPanel>
        <template #header>
            <UDashboardNavbar title="Editar Cliente">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <div class="p-4 sm:p-6">
                <div class="mb-6">
                    <h2 class="text-2xl font-semibold">Editar Cliente</h2>
                    <p class="text-sm text-muted mt-1">
                        Modifique los datos del cliente
                    </p>
                </div>

                <form @submit.prevent="handleSubmit" class="space-y-6">
                    <!-- Fila 1: Tipo y Número de Documento -->
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <UFormField
                            label="Tipo de Documento"
                            name="tipoDocumento"
                            required
                            :error="errors.tipoDocumento"
                            size="xl"
                            class="w-full"
                        >
                            <USelect
                                v-model="state.tipoDocumento"
                                :items="tiposDocumentoColombia"
                                placeholder="Seleccione tipo de documento"
                                size="xl"
                                class="w-full"
                            />
                        </UFormField>

                        <UFormField
                            label="Número de Documento"
                            name="numeroDocumento"
                            required
                            :error="errors.numeroDocumento"
                            :hint="ayudaDocumento(state.tipoDocumento)"
                            size="xl"
                            class="w-full"
                        >
                            <UInput
                                v-model="state.numeroDocumento"
                                placeholder="Ingrese el número de documento"
                                icon="i-lucide-credit-card"
                                :inputmode="
                                    documentoNumerico(state.tipoDocumento)
                                        ? 'numeric'
                                        : 'text'
                                "
                                :maxlength="
                                    longitudDocumento(state.tipoDocumento)
                                "
                                autocomplete="off"
                                required
                                size="xl"
                                class="w-full"
                                @update:model-value="
                                    state.numeroDocumento = normalizarDocumento(
                                        String($event),
                                        state.tipoDocumento,
                                    )
                                "
                            />
                        </UFormField>
                    </div>

                    <!-- Fila 2: Razón Social y Dirección -->
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <UFormField
                            label="Razón Social"
                            name="razonSocial"
                            required
                            :error="errors.razonSocial"
                            size="xl"
                            class="w-full"
                        >
                            <UInput
                                v-model="state.razonSocial"
                                placeholder="Ingrese la razón social"
                                icon="i-lucide-building"
                                size="xl"
                                class="w-full"
                                required
                            />
                        </UFormField>

                        <UFormField
                            label="Dirección"
                            name="direccion"
                            required
                            :error="errors.direccion"
                            size="xl"
                            class="w-full"
                        >
                            <UInput
                                v-model="state.direccion"
                                placeholder="Ingrese la dirección"
                                icon="i-lucide-map-pin"
                                size="xl"
                                class="w-full"
                                required
                            />
                        </UFormField>
                    </div>

                    <!-- Fila 3: Teléfono y Email -->
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <UFormField
                            label="Teléfono"
                            name="telefono"
                            required
                            :error="errors.telefono"
                            size="xl"
                            class="w-full"
                        >
                            <UInput
                                v-model="state.telefono"
                                type="tel"
                                inputmode="tel"
                                autocomplete="tel"
                                maxlength="13"
                                placeholder="0991234567"
                                icon="i-lucide-phone"
                                required
                                size="xl"
                                class="w-full"
                                @update:model-value="
                                    state.telefono = normalizarTelefono(
                                        String($event),
                                    )
                                "
                            />
                        </UFormField>

                        <UFormField
                            label="Email"
                            name="email"
                            required
                            :error="errors.email"
                            size="xl"
                            class="w-full"
                        >
                            <UInput
                                v-model="state.email"
                                type="email"
                                autocomplete="email"
                                maxlength="254"
                                required
                                placeholder="cliente@ejemplo.com"
                                icon="i-lucide-mail"
                                size="xl"
                                class="w-full"
                            />
                        </UFormField>
                    </div>

                    <!-- Información adicional -->
                    <div class="bg-muted/50 rounded-lg p-4 text-sm">
                        <p class="text-muted">
                            <strong>ID:</strong> {{ cliente.id }}
                        </p>
                        <p class="text-muted mt-1">
                            <strong>Creado:</strong>
                            {{
                                new Date(cliente.createdAt).toLocaleString(
                                    "es-ES",
                                )
                            }}
                        </p>
                        <p class="text-muted mt-1">
                            <strong>Actualizado:</strong>
                            {{
                                new Date(cliente.updatedAt).toLocaleString(
                                    "es-ES",
                                )
                            }}
                        </p>
                    </div>

                    <!-- Botones -->
                    <div
                        class="flex flex-col-reverse gap-3 pt-4 sm:flex-row sm:justify-end"
                    >
                        <UButton
                            type="button"
                            color="neutral"
                            variant="outline"
                            label="Cancelar"
                            @click="handleCancel"
                            :disabled="isLoading"
                        />
                        <UButton
                            type="submit"
                            color="primary"
                            label="Actualizar Cliente"
                            icon="i-lucide-save"
                            :loading="isLoading"
                        />
                    </div>
                </form>
            </div>
        </template>
    </UDashboardPanel>
</template>
