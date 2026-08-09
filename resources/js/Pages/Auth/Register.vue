<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import AutofixLogo from "../../components/AutofixLogo.vue";
import FormField from "../../components/FormField.vue";
import {
    ayudaDocumento,
    documentoNumerico,
    longitudDocumento,
    normalizarDocumento,
    normalizarTelefono,
    tiposDocumentoColombia,
} from "../../utils/validation";

defineOptions({ layout: null });

const state = reactive({
    tipoDocumento: "CC",
    numeroDocumento: "",
    razonSocial: "",
    direccion: "",
    telefono: "",
    email: "",
    password: "",
    password_confirmation: "",
});

const page = usePage();
const errors = computed<Record<string, string>>(
    () => page.props.errors as Record<string, string>,
);
const isLoading = ref(false);

watch(
    () => state.tipoDocumento,
    (tipo) => {
        state.numeroDocumento = normalizarDocumento(
            state.numeroDocumento,
            tipo,
        );
    },
);

function handleSubmit() {
    isLoading.value = true;
    router.post(route("register"), state, {
        onFinish: () => {
            isLoading.value = false;
        },
    });
}
</script>

<template>
    <Head title="Crear cuenta" />
    <main class="min-h-screen bg-background px-4 py-8 sm:py-12">
        <div class="mx-auto w-full max-w-3xl">
            <div class="mb-6 flex items-center justify-center gap-3">
                <AutofixLogo class="h-16 w-20 shrink-0" />
                <div>
                    <p class="text-2xl font-bold">AUTOFIX</p>
                    <p class="text-sm text-muted">
                        Tu cuenta para gestionar vehículos y citas
                    </p>
                </div>
            </div>

            <UCard>
                <template #header>
                    <div>
                        <h1 class="text-xl font-bold">
                            Crear cuenta de cliente
                        </h1>
                        <p class="mt-1 text-sm text-muted">
                            Registra tus datos. La cuenta se crea únicamente con
                            permisos de Cliente.
                        </p>
                    </div>
                </template>

                <form
                    class="grid gap-5 md:grid-cols-2"
                    @submit.prevent="handleSubmit"
                >
                    <FormField
                        label="Tipo de documento"
                        name="tipoDocumento"
                        required
                        :error="errors.tipoDocumento"
                    >
                        <template #default="{ fieldAttrs }">
                            <USelect
                                v-bind="fieldAttrs"
                                v-model="state.tipoDocumento"
                                :items="tiposDocumentoColombia"
                                class="w-full"
                                size="xl"
                            />
                        </template>
                    </FormField>

                    <FormField
                        label="Número de documento"
                        name="numeroDocumento"
                        required
                        :error="errors.numeroDocumento"
                        :hint="ayudaDocumento(state.tipoDocumento)"
                    >
                        <template #default="{ fieldAttrs }">
                            <UInput
                                v-bind="fieldAttrs"
                                v-model="state.numeroDocumento"
                                :inputmode="
                                    documentoNumerico(state.tipoDocumento)
                                        ? 'numeric'
                                        : 'text'
                                "
                                :maxlength="
                                    longitudDocumento(state.tipoDocumento)
                                "
                                autocomplete="off"
                                class="w-full"
                                icon="i-lucide-id-card"
                                size="xl"
                                @update:model-value="
                                    state.numeroDocumento = normalizarDocumento(
                                        String($event),
                                        state.tipoDocumento,
                                    )
                                "
                            />
                        </template>
                    </FormField>

                    <FormField
                        class="md:col-span-2"
                        label="Nombre completo o razón social"
                        name="razonSocial"
                        required
                        :error="errors.razonSocial"
                    >
                        <template #default="{ fieldAttrs }">
                            <UInput
                                v-bind="fieldAttrs"
                                v-model="state.razonSocial"
                                autocomplete="name"
                                class="w-full"
                                icon="i-lucide-user"
                                size="xl"
                            />
                        </template>
                    </FormField>

                    <FormField
                        label="Teléfono"
                        name="telefono"
                        required
                        :error="errors.telefono"
                        hint="Número ecuatoriano móvil de 10 dígitos, fijo de 9 o con prefijo +593."
                    >
                        <template #default="{ fieldAttrs }">
                            <UInput
                                v-bind="fieldAttrs"
                                v-model="state.telefono"
                                type="tel"
                                inputmode="tel"
                                autocomplete="tel"
                                maxlength="13"
                                class="w-full"
                                icon="i-lucide-phone"
                                size="xl"
                                @update:model-value="
                                    state.telefono = normalizarTelefono(
                                        String($event),
                                    )
                                "
                            />
                        </template>
                    </FormField>

                    <FormField
                        label="Dirección"
                        name="direccion"
                        required
                        :error="errors.direccion"
                    >
                        <template #default="{ fieldAttrs }">
                            <UInput
                                v-bind="fieldAttrs"
                                v-model="state.direccion"
                                autocomplete="street-address"
                                class="w-full"
                                icon="i-lucide-map-pin"
                                size="xl"
                            />
                        </template>
                    </FormField>

                    <FormField
                        class="md:col-span-2"
                        label="Correo electrónico"
                        name="email"
                        required
                        :error="errors.email"
                    >
                        <template #default="{ fieldAttrs }">
                            <UInput
                                v-bind="fieldAttrs"
                                v-model="state.email"
                                type="email"
                                autocomplete="email"
                                maxlength="254"
                                class="w-full"
                                icon="i-lucide-mail"
                                size="xl"
                            />
                        </template>
                    </FormField>

                    <FormField
                        label="Contraseña"
                        name="password"
                        required
                        :error="errors.password"
                        hint="Mínimo 8 caracteres, con mayúsculas, minúsculas y números."
                    >
                        <template #default="{ fieldAttrs }">
                            <UInput
                                v-bind="fieldAttrs"
                                v-model="state.password"
                                type="password"
                                autocomplete="new-password"
                                class="w-full"
                                icon="i-lucide-lock"
                                size="xl"
                            />
                        </template>
                    </FormField>

                    <FormField
                        label="Confirmar contraseña"
                        name="password_confirmation"
                        required
                        :error="errors.passwordConfirmation"
                    >
                        <template #default="{ fieldAttrs }">
                            <UInput
                                v-bind="fieldAttrs"
                                v-model="state.password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                class="w-full"
                                icon="i-lucide-lock-keyhole"
                                size="xl"
                            />
                        </template>
                    </FormField>

                    <div class="md:col-span-2">
                        <UAlert
                            color="neutral"
                            variant="subtle"
                            icon="i-lucide-shield-check"
                            title="Registro exclusivo para clientes"
                            description="Los roles de Administrador, Mecánico y Recepcionista solo pueden ser asignados por un administrador."
                        />
                    </div>

                    <div
                        class="flex flex-col-reverse gap-3 md:col-span-2 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p class="text-center text-sm text-muted sm:text-left">
                            ¿Ya tienes cuenta?
                            <UButton
                                :to="route('login')"
                                variant="link"
                                label="Inicia sesión"
                                :padded="false"
                            />
                        </p>
                        <UButton
                            type="submit"
                            label="Crear mi cuenta"
                            icon="i-lucide-user-plus"
                            size="xl"
                            :loading="isLoading"
                        />
                    </div>
                </form>
            </UCard>
        </div>
    </main>
</template>
