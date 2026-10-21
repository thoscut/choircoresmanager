/* eslint-disable */
/* tslint:disable */
// @ts-nocheck
/*
 * ---------------------------------------------------------------
 * ## THIS FILE WAS GENERATED VIA SWAGGER-TYPESCRIPT-API        ##
 * ##                                                           ##
 * ## AUTHOR: acacode                                           ##
 * ## SOURCE: https://github.com/acacode/swagger-typescript-api ##
 * ---------------------------------------------------------------
 */

import {
  AdminGetEditGroupsData,
  AdminGetRepertoireThresholdsData,
  AdminPostEditGroupsData,
  AdminPostEditGroupsPayload,
  AdminPostRepertoireThresholdsData,
  AdminPostRepertoireThresholdsPayload,
  OCSMeta,
} from "./data-contracts";
import { ContentType, HttpClient, RequestParams } from "./http-client";

export class Ocs<
  SecurityDataType = unknown,
> extends HttpClient<SecurityDataType> {
  /**
   * @description Current settings This endpoint requires admin access
   *
   * @tags admin
   * @name AdminGetEditGroups
   * @summary Get current list of groups allowed to edit scores
   * @request GET:/ocs/v2.php/apps/orchestrascoresmanager/admin/editGroups
   * @secure
   */
  adminGetEditGroups = (params: RequestParams = {}) =>
    this.request<
      AdminGetEditGroupsData,
      {
        ocs: {
          meta: OCSMeta;
          data: any;
        };
      }
    >({
      path: `/ocs/v2.php/apps/orchestrascoresmanager/admin/editGroups`,
      method: "GET",
      secure: true,
      format: "json",
      ...params,
    });
  /**
   * @description Updated settings This endpoint requires admin access This endpoint requires password confirmation
   *
   * @tags admin
   * @name AdminPostEditGroups
   * @summary Update which groups are allowed to edit scores
   * @request POST:/ocs/v2.php/apps/orchestrascoresmanager/admin/editGroups
   * @secure
   */
  adminPostEditGroups = (
    data?: AdminPostEditGroupsPayload,
    params: RequestParams = {},
  ) =>
    this.request<
      AdminPostEditGroupsData,
      {
        ocs: {
          meta: OCSMeta;
          data: any;
        };
      }
    >({
      path: `/ocs/v2.php/apps/orchestrascoresmanager/admin/editGroups`,
      method: "POST",
      body: data,
      secure: true,
      type: ContentType.Json,
      format: "json",
      ...params,
    });
  /**
   * @description Current thresholds This endpoint requires admin access
   *
   * @tags admin
   * @name AdminGetRepertoireThresholds
   * @summary Get the thresholds used to derive the repertoire class of a score
   * @request GET:/ocs/v2.php/apps/orchestrascoresmanager/admin/repertoireThresholds
   * @secure
   */
  adminGetRepertoireThresholds = (params: RequestParams = {}) =>
    this.request<
      AdminGetRepertoireThresholdsData,
      {
        ocs: {
          meta: OCSMeta;
          data: any;
        };
      }
    >({
      path: `/ocs/v2.php/apps/orchestrascoresmanager/admin/repertoireThresholds`,
      method: "GET",
      secure: true,
      format: "json",
      ...params,
    });
  /**
   * @description Updated thresholds This endpoint requires admin access This endpoint requires password confirmation
   *
   * @tags admin
   * @name AdminPostRepertoireThresholds
   * @summary Update the thresholds used to derive the repertoire class of a score
   * @request POST:/ocs/v2.php/apps/orchestrascoresmanager/admin/repertoireThresholds
   * @secure
   */
  adminPostRepertoireThresholds = (
    data: AdminPostRepertoireThresholdsPayload,
    params: RequestParams = {},
  ) =>
    this.request<
      AdminPostRepertoireThresholdsData,
      {
        ocs: {
          meta: OCSMeta;
          data: any;
        };
      }
    >({
      path: `/ocs/v2.php/apps/orchestrascoresmanager/admin/repertoireThresholds`,
      method: "POST",
      body: data,
      secure: true,
      type: ContentType.Json,
      format: "json",
      ...params,
    });
}
